/**
 * End-to-end coverage of Products -> Import.
 *
 * Note on the upload step: `setInputFiles()` is the whole action. Choosing a
 * workbook submits the form — assets/js/import-admin.js auto-submits on the
 * input's `change` event — so there is no button to press afterwards. The
 * "Check workbook" submit button still exists in the markup for the no-JS
 * case, but the same script hides it, so it is never clickable here.
 */
const path = require('node:path');
const { test, expect } = require('@playwright/test');
const {
	wp, productCount, counts, resetCatalogue, cleanupUploadRuns,
	buildFixture, modifyWorkbookCell, seedRenameSource, productIdBySku, trashedProductIdBySku, postStatus,
	permalinkFor, withoutCapability, runDirectoryCount, seedAgedRuns, plantWorkbookInRun, runDirectoryExists,
	CORRECTED, SOURCE, IMPORT_PATH,
} = require('./helpers');

test.describe('Product import', () => {
	test.beforeAll(() => resetCatalogue());
	test.afterAll(() => {
		resetCatalogue();
		cleanupUploadRuns();
	});

	test('the import screen is reachable from the Products menu', async ({ page }) => {
		await page.goto(IMPORT_PATH);

		await expect(page.getByRole('heading', { name: 'Import products' })).toBeVisible();

		// The file input is deliberately not visible: it is the standard
		// hidden-input-plus-label pattern, so what a sighted user actually
		// operates is the "Choose file…" label and the drop target around it.
		// Assert those, and assert the input is present and enabled — checking
		// the input for visibility would pass on a 1px clipped element and
		// prove nothing about what is on screen.
		await expect(page.getByText('Drop the product workbook here')).toBeVisible();
		await expect(page.locator('label[for="cadco-import-file"]')).toBeVisible();

		const input = page.locator('input[type="file"]');
		await expect(input).toBeAttached();
		await expect(input).toBeEnabled();

		// The submit button is the no-JS fallback only; the script hides it and
		// submits on file choice instead. If this ever becomes visible, the
		// auto-submit has failed to initialise.
		await expect(page.locator('#cadco-import-submit-fallback')).toBeHidden();
	});

	// Gap: admin_enqueue_scripts has never run for real. The whole apply flow
	// depends on str_contains($hook, 'cadco-import') matching the real hook
	// suffix WordPress builds for this submenu page — if it does not match,
	// the page still renders and the Apply button still appears, but nothing
	// is wired up: no window.cadcoImport, no click handler, no clue why.
	test('the import screen enqueues its script and localizes its config', async ({ page }) => {
		await page.goto(IMPORT_PATH);

		await expect(page.locator('script[src*="import-admin.js"]')).toHaveCount(1);

		const config = await page.evaluate(() => window.cadcoImport);

		expect(config).toBeTruthy();
		expect(config.ajaxUrl).toContain('admin-ajax.php');
		expect(typeof config.nonce).toBe('string');
		expect(config.nonce.length).toBeGreaterThan(0);
		// wp_localize_script() casts every scalar value to a string before
		// it reaches the browser — this is standard WordPress behaviour, not
		// something this theme controls, so the check is loose on type.
		expect(Number(config.batchSize)).toBe(25);
		expect(config.i18n).toMatchObject({
			failed: expect.any(String),
			network: expect.any(String),
			done: expect.any(String),
			doneWithFailures: expect.any(String),
			failuresHeading: expect.any(String),
		});
	});

	test('the screen refuses a user without a session', async ({ browser }) => {
		const context = await browser.newContext({ ignoreHTTPSErrors: true, storageState: undefined });
		const page = await context.newPage();

		await page.goto(IMPORT_PATH);
		await expect(page).toHaveURL(/wp-login\.php/);

		await context.close();
	});

	// Gap: only the *allow* path of current_user_can(CAPABILITY) has ever run —
	// the 403 branch has never fired for a real, logged-in session. The test
	// above proves WordPress won't show wp-admin to a stranger; this one
	// proves the capability gate itself denies a signed-in administrator the
	// moment it lacks manage_woocommerce, on both the page and the AJAX
	// handler behind the Apply button.
	test('a logged-in user without the capability is refused on the page and in the AJAX handler', async ({ page }) => {
		// Captured while the session still has the capability: a nonce's
		// validity depends on the user ID and session token, never on the
		// capability set, so this nonce is still exactly what a real click
		// on this page would have sent — it lets the AJAX request below
		// reach current_user_can() instead of dying earlier at the referer
		// check, which is the branch this test exists to exercise.
		await page.goto(IMPORT_PATH);
		const nonce = await page.evaluate(() => window.cadcoImport.nonce);
		expect(nonce).toBeTruthy();

		await withoutCapability('manage_woocommerce', async () => {
			await page.goto(IMPORT_PATH);

			// WordPress core denies this before CADCO_Import_Admin::render()
			// is ever called: add_submenu_page()'s own capability check
			// populates $_wp_submenu_nopriv on this request, and
			// wp-admin/admin.php dies with its own generic message. render()'s
			// own current_user_can()+wp_die() is therefore unreachable for a
			// direct page visit — real, but defensive/dead code — which is
			// exactly why the AJAX side below matters: nothing upstream of
			// ajax_batch() gates it the same way.
			await expect(page.getByRole('heading', { name: 'Import products' })).toHaveCount(0);
			await expect(page.locator('body')).toContainText('Sorry, you are not allowed to access this page.');

			const ajaxUrl = new URL('/wp-admin/admin-ajax.php', page.url()).toString();
			const response = await page.request.post(ajaxUrl, {
				form: { action: 'cadco_import_batch', _wpnonce: nonce },
			});

			expect(response.status()).toBe(403);

			const body = await response.json();
			expect(body.success).toBe(false);
		});

		// The capability is restored by withoutCapability()'s finally block —
		// confirm the page is reachable again so a failure here fails loudly
		// rather than leaving every later test silently running unprivileged.
		await page.goto(IMPORT_PATH);
		await expect(page.getByRole('heading', { name: 'Import products' })).toBeVisible();
	});

	test('a workbook with problems is reported and writes nothing', async ({ page }) => {
		const before = productCount();

		await page.goto(IMPORT_PATH);
		await page.setInputFiles('input[type="file"]', SOURCE);

		// The report must say plainly that nothing happened.
		await expect(page.locator('.notice-error')).toContainText(/nothing has been imported/i);

		// All three tiers are named, so the client can act on the report.
		await expect(page.getByRole('heading', { name: /Identity/ })).toBeVisible();
		await expect(page.getByRole('heading', { name: /Consistency/ })).toBeVisible();
		await expect(page.getByRole('heading', { name: /Completeness/ })).toBeVisible();

		// Every issue names where it is and what to do about it.
		const firstRow = page.locator('table.widefat tbody tr').first();
		await expect(firstRow.locator('td').nth(0)).not.toBeEmpty(); // sheet
		await expect(firstRow.locator('td').nth(5)).not.toBeEmpty(); // how to fix

		// There must be no Apply button anywhere on a failing report.
		await expect(page.locator('#cadco-import-apply')).toHaveCount(0);

		const after = counts();
		expect(after.published).toBe(before);
		expect(after.trashed).toBe(0);
	});

	test('a clean workbook previews a plan without writing anything', async ({ page }) => {
		const before = productCount();

		await page.goto(IMPORT_PATH);
		await page.setInputFiles('input[type="file"]', CORRECTED);

		await expect(page.locator('.notice-success')).toBeVisible();
		await expect(page.locator('.cadco-import-counts')).toContainText('236');
		await expect(page.locator('#cadco-import-apply')).toBeVisible();

		// This is the dry run: a plan is on screen and the catalogue is untouched.
		expect(productCount()).toBe(before);
	});

	// Gap: zero JavaScript coverage of the failure list. run_job() catches a
	// row's WC_Data_Exception and logs "FAILED <model>: <reason>" rather than
	// aborting the batch; nothing had ever exercised the client-side code that
	// notices those lines. Provoking a real WooCommerce failure inside a
	// 236-product batch would be slow and hard to aim, so this intercepts the
	// one AJAX call the click makes and answers it with a batch that contains
	// a failure — the click, the fetch, and every line of DOM update from the
	// real response handler still run for real in the browser.
	test('a failed row is reported in the failures list and counted out of "done"', async ({ page }) => {
		await page.goto(IMPORT_PATH);
		await page.setInputFiles('input[type="file"]', CORRECTED);
		await expect(page.locator('#cadco-import-apply')).toBeVisible();

		await page.route('**/admin-ajax.php', (route) => route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				success: true,
				data: {
					done: 4,
					total: 4,
					complete: true,
					log: [
						'Created WIDGET-A (#101)',
						'FAILED WIDGET-B: A duplicate SKU was rejected by WooCommerce.',
						'Created WIDGET-C (#102)',
						'Created WIDGET-D (#103)',
					],
				},
			}),
		}));

		await page.click('#cadco-import-apply');

		const failures = page.locator('#cadco-import-failures');
		await expect(failures).toBeVisible();
		await expect(failures.locator('.cadco-import-failures-heading')).toContainText('1');
		await expect(failures.locator('.cadco-import-failures-list li')).toHaveCount(1);
		await expect(failures.locator('.cadco-import-failures-list li')).toContainText('FAILED WIDGET-B');

		// data.total (4) is the queue length, not a success count — the
		// applied figure the operator reads must be total minus failures (3),
		// never the raw total.
		const status = page.locator('.cadco-import-status');
		await expect(status).toContainText('3');
		await expect(status).toContainText('4');
		await expect(status).toContainText(/failed/i);
	});

	// Gap: the JS network-error branch (the fetch().catch()) has never run —
	// every prior exercise of this code has been against a server that
	// answers. Aborting the one AJAX request the click makes forces a real
	// browser-level network failure into the real click handler.
	test('a network failure during apply is reported and the button re-enables', async ({ page }) => {
		await page.goto(IMPORT_PATH);
		await page.setInputFiles('input[type="file"]', CORRECTED);
		await expect(page.locator('#cadco-import-apply')).toBeVisible();

		await page.route('**/admin-ajax.php', (route) => route.abort('failed'));

		await page.click('#cadco-import-apply');

		await expect(page.locator('.cadco-import-status')).toContainText(/import request failed/i);
		await expect(page.locator('#cadco-import-apply')).toBeEnabled();
	});

	// Gap: nobody has loaded a workbook containing a script tag and watched it
	// not fire. A tiny fixture keeps this fast and isolated from the 236-row
	// apply below; the catalogue is reset back to empty afterwards so it
	// cannot influence the counts the big apply test asserts.
	//
	// This proves three things about a product name carrying markup: the
	// markup is stripped on import (CADCO_Import_Applier::write_product()
	// runs Product Name through wp_strip_all_tags() before it ever reaches
	// WooCommerce), no script from it can execute, and the surrounding
	// legitimate text is not discarded along with the markup — only the tag
	// goes. It deliberately does not assert that the raw markup is visible
	// as literal text on the page: that would be testing for escaping, a
	// different (and not required) remediation. "The script does not run"
	// is the security property; "the words survive" is what proves the
	// sanitiser did not overreach into deleting the whole product name.
	test('a script tag in a product name is stripped on import and cannot execute', async ({ page }) => {
		const fixture = buildFixture('xss');

		await page.goto(IMPORT_PATH);
		await page.setInputFiles('input[type="file"]', fixture);
		await expect(page.locator('#cadco-import-apply')).toBeVisible();

		await page.click('#cadco-import-apply');
		await expect(page.locator('.cadco-import-status')).toContainText(/Done/i, { timeout: 30000 });

		try {
			const productId = productIdBySku('E2E-XSS-1');
			expect(productId).not.toBeNull();

			const permalink = permalinkFor(productId);

			let dialogFired = false;
			page.once('dialog', (dialog) => { dialogFired = true; dialog.dismiss(); });

			await page.goto(permalink);

			// The security property: nothing from the payload ever ran.
			const xssRan = await page.evaluate(() => window.__xss);
			expect(xssRan).toBeUndefined();
			expect(dialogFired).toBe(false);

			// Belt and braces on the same property: no script element carrying
			// the payload exists anywhere in the page at all, so there is
			// nothing left that a different execution path could trigger.
			await expect(page.locator('script', { hasText: 'window.__xss' })).toHaveCount(0);

			// The important half: the sanitiser removed only the markup, not
			// the product name it was embedded in. Fixture title is
			// 'XSS <script>window.__xss=1</script> Probe' — the words on
			// either side of the tag must still be there.
			await expect(page.locator('body')).toContainText('XSS');
			await expect(page.locator('body')).toContainText('Probe');
		} finally {
			// Runs whether the assertions above passed or not — a failure
			// here must not leave the 4 fixture products (or their terms)
			// behind for the next test to trip over.
			resetCatalogue();
		}
	});

	// Gap: the test above proves the *front-end product page* is safe, which
	// only exercises CADCO_Import_Applier::write_product()'s
	// wp_strip_all_tags() call — a step that only runs once a workbook is
	// actually applied. Nothing had ever loaded a workbook carrying markup
	// and inspected the Review screen itself (Task 7): the plan tables
	// (create_table()) and the new Categories tree (section_categories())
	// both render straight from the still-raw, unstripped workbook row, via
	// esc_html() rather than stripping. Two prior XSS findings in this
	// system are exactly why that gap matters. This never clicks Apply, so
	// it is checking the review screen alone, before anything is stripped
	// by anything downstream.
	//
	// Unlike the test above, this one *does* assert the raw markup is
	// visible as literal text: esc_html() neutralises a tag by encoding it
	// (`&lt;script&gt;`), it does not remove it the way
	// wp_strip_all_tags() does, so "the words on both sides of the tag, and
	// the tag's own text, are all still there" is the correct proof of
	// escaping — the same words-survive check as above, plus the literal
	// tag text this time, because escaping and stripping leave different,
	// both legitimate, marks on the page.
	test('a script tag in a product name or category is inert on the review screen before anything is applied', async ({ page }) => {
		const fixture = buildFixture('xss-review');

		await page.goto(IMPORT_PATH);
		await page.setInputFiles('input[type="file"]', fixture);

		// Confirms the workbook was clean and Review actually rendered —
		// this test never clicks it, on purpose: the assertions below must
		// hold before any apply, not after.
		await expect(page.locator('#cadco-import-apply')).toBeVisible();

		let dialogFired = false;
		page.once('dialog', (dialog) => { dialogFired = true; dialog.dismiss(); });

		// The security property: nothing from either payload ever ran, and
		// no script element carrying either payload exists anywhere in the
		// review page at all.
		const productPayloadRan = await page.evaluate(() => window.__xssReview);
		const categoryPayloadRan = await page.evaluate(() => window.__xssReviewCat);
		expect(productPayloadRan).toBeUndefined();
		expect(categoryPayloadRan).toBeUndefined();
		expect(dialogFired).toBe(false);
		await expect(page.locator('script', { hasText: 'window.__xssReview' })).toHaveCount(0);

		// Products to create: the row for E2E-XSS-REVIEW-1 shows the product
		// name's surrounding words and the neutralised tag as literal text.
		// Scoped to #cadco-section-products specifically, not just any
		// table.widefat containing that text — the Workbook section's own
		// summary table shows this fixture's generated filename
		// ("cadco-e2e-xss-review-<timestamp>-<pid>.xlsx"), and Playwright's
		// hasText does a case-insensitive substring match, so the digits
		// straight after "review-" can coincide with the "-1" this model
		// number ends in and match the wrong table.
		const productRow = page.locator('#cadco-section-products tbody tr').filter({ hasText: 'E2E-XSS-REVIEW-1' }).first();
		await expect(productRow).toContainText('XSS Review');
		await expect(productRow).toContainText('Probe');
		await expect(productRow).toContainText('<script>window.__xssReview=1</script>');

		// The Categories tree (Task 7's new section): the new category's
		// name carries the same payload, and must render exactly as inert.
		const categoriesSection = page.locator('#cadco-section-categories');
		await expect(categoriesSection).toContainText('XssCategory');
		await expect(categoriesSection).toContainText('Marker');
		await expect(categoriesSection).toContainText('<script>window.__xssReviewCat=1</script>');
	});

	// Gap: rename approval is by UPC (the checkbox's `value`), not array
	// index. This is the one code path that actually depends on the JS
	// reading `.cadco-rename:checked` and encoding it into `approved[]`
	// correctly — a plain create/update never touches that code at all.
	test('an approved rename keeps the product\'s post ID and applies the new model number', async ({ page }) => {
		const seededId = seedRenameSource();
		const fixture = buildFixture('rename');

		try {
			await page.goto(IMPORT_PATH);
			await page.setInputFiles('input[type="file"]', fixture);

			const renameRow = page.locator('table.widefat').filter({ hasText: 'E2E-RENAME-OLD-1' }).locator('tbody tr').first();
			await expect(renameRow).toBeVisible();

			const checkbox = renameRow.locator('input.cadco-rename');
			await expect(checkbox).toBeChecked();
			await expect(checkbox).toHaveValue('654796-99020-1');

			await page.click('#cadco-import-apply');
			await expect(page.locator('.cadco-import-status')).toContainText(/Done/i, { timeout: 30000 });

			expect(productIdBySku('E2E-RENAME-OLD-1')).toBeNull();

			const renamedId = productIdBySku('E2E-RENAME-NEW-1');
			expect(renamedId).toBe(seededId);
		} finally {
			resetCatalogue();
		}
	});

	// Blocker: nothing, unit or E2E, had ever run run_job()'s wp_trash_post()
	// branch before this test existed. PlannerTest only proves the *planner*
	// emits a trash job for a product missing from the workbook — nobody had
	// exercised the Applier actually carrying it out. The first production
	// import cannot exercise it either (the catalogue starts at 0 products),
	// so all the risk of a broken trash path would otherwise land on the
	// second real import, against real data, with no dry run available for
	// "removed" rows the way there is for creates and updates.
	test('a product missing from a re-import is trashed, not deleted', async ({ page }) => {
		resetCatalogue();

		try {
			// Step 1: import a fixture carrying two purpose-built rows on the
			// target sheet. completeSheets() fills the other three canonical
			// sheets with their own single default row each — a sheet the
			// Reader doesn't see at all is a Tier A error, so every workbook
			// this suite builds must carry all four — which means the
			// catalogue this produces is 5 products, not 2: the two rows this
			// test cares about, plus three untouched "background" products
			// that stay present and unchanged (skipped) through both imports
			// below. Both counts here are exact, not the fixture's own row
			// count, precisely because of that background.
			const full = buildFixture('trash-full');

			await page.goto(IMPORT_PATH);
			await page.setInputFiles('input[type="file"]', full);
			await expect(page.locator('#cadco-import-apply')).toBeVisible();

			await page.click('#cadco-import-apply');
			await expect(page.locator('.cadco-import-status')).toContainText(/Done/i, { timeout: 30000 });

			const afterFull = counts();
			expect(afterFull.published).toBe(5);
			expect(afterFull.trashed).toBe(0);

			// Step 2: re-import with the second target row gone (the three
			// background rows are byte-identical, so they hash the same and
			// are skipped, not updated). The plan must offer exactly one
			// trash for the product the workbook no longer lists.
			const reduced = buildFixture('trash-reduced');

			await page.goto(IMPORT_PATH);
			await page.setInputFiles('input[type="file"]', reduced);
			await expect(page.locator('.cadco-import-counts')).toBeVisible();
			await expect(page.locator('.cadco-import-counts')).toContainText(/1[\s\S]*to trash/i);

			// Step 3: apply. Four products remain published (one target row
			// plus the three untouched background rows); the fifth is trashed.
			await page.click('#cadco-import-apply');
			await expect(page.locator('.cadco-import-status')).toContainText(/Done/i, { timeout: 30000 });

			const afterReduced = counts();
			expect(afterReduced.published).toBe(4);
			expect(afterReduced.trashed).toBe(1);

			// Step 4: the removed product is genuinely trashed, never deleted —
			// trash-never-delete is an explicit design rule, and a hard delete
			// would also make it "unfindable", which is why this looks it up by
			// meta on trashed posts specifically rather than by absence.
			const trashedId = trashedProductIdBySku('E2E-TRASH-2');
			expect(trashedId).not.toBeNull();
			expect(postStatus(trashedId)).toBe('trash');

			// The surviving product is untouched, not re-created under a new ID.
			const keptId = productIdBySku('E2E-TRASH-1');
			expect(keptId).not.toBeNull();
		} finally {
			resetCatalogue();
		}
	});

	test('applying the plan imports the catalogue without timing out', async ({ page }) => {
		test.setTimeout(300000);

		await page.goto(IMPORT_PATH);
		await page.setInputFiles('input[type="file"]', CORRECTED);
		await expect(page.locator('#cadco-import-apply')).toBeVisible();

		await page.click('#cadco-import-apply');

		// Progress must actually move — a single blocking request would jump
		// straight from 0 to done, or time out.
		const status = page.locator('.cadco-import-status');
		await expect(status).toContainText(/\d+ \/ \d+/, { timeout: 30000 });

		await expect(status).toContainText(/Done/i, { timeout: 240000 });

		const final = counts();
		expect(final.published).toBe(236);
		expect(final.tags).toBe(26);
		expect(final.brands).toBe(6);
	});

	// Task 16: the redirect map used to hold only approved renames — the
	// legacy `Website URL` half (the old cadco-ltd.com site) was never
	// implemented, so every one of the real product URLs from the old site
	// would 404 once this one goes live. This proves the derived half end to
	// end, against the real corrected workbook's real data: a known
	// product's legacy path resolves to its real new URL, and the two rows
	// where 'Website URL' actually holds a spec-sheet PDF (a data-entry
	// error in the source workbook) are skipped rather than exported as a
	// redirect from a PDF path.
	//
	// 225, not 232: the corrected workbook has 234 rows with a non-blank
	// 'Website URL', of which 232 are shaped like a real product page
	// (/product/<slug>) rather than one of the two PDF rows — but those 232
	// are not all *distinct* paths. 7 pairs of genuinely different products
	// (different SKU, different post) carry the exact same legacy URL — e.g.
	// XHC-020-S1 and XHC-020-P1 both claim
	// '/product/xhc-020-p1' — a real duplicate in the source data, confirmed
	// by direct inspection, not a bug in path extraction. redirect_map() is
	// keyed by the 'from' path, so a legacy URL claimed twice can only ever
	// redirect to one of the two new pages; the second claimant silently
	// loses the collision. 232 legacy paths therefore collapse to 225 unique
	// map entries. See task-16-report.md for the full list of collisions.
	test('the redirect map exports a legacy path for a known product and skips the two PDF rows', async ({ page }) => {
		await page.goto(IMPORT_PATH);
		const nonce = await page.evaluate(() => window.cadcoImport.nonce);

		const response = await page.request.get(
			`/wp-admin/edit.php?post_type=product&page=cadco-import&action=export-redirects&_wpnonce=${nonce}`
		);
		expect(response.status()).toBe(200);

		const csv = await response.text();
		const lines = csv.trim().split('\n');

		expect(lines[0]).toContain('Old path');
		expect(lines[0]).toContain('New URL');

		const rows = lines.slice(1);
		expect(rows).toHaveLength(225);

		// fputcsv() only quotes a field that needs it (contains the
		// delimiter, the enclosure, a newline, or whitespace) — the header
		// row is quoted because 'Old path' and 'New URL' contain spaces, but
		// a plain path or URL is not, so data rows are unquoted.
		const blcRow = rows.find((line) => line.startsWith('/product/xaf-113,'));
		expect(blcRow).toBeTruthy();
		expect(blcRow).toContain('/products/convection-ovens/bakerlux-classic/blc-113/');

		// VK-VH-FK -> varikwik-hood-filter-2.pdf, PS-TBS-HD ->
		// wt-hd-warming-shelves-accessories-spec-rv1-stainless-1.pdf. Neither
		// is a product page, so neither may appear as a redirect source.
		expect(csv).not.toContain('varikwik-hood-filter');
		expect(csv).not.toContain('wt-hd-warming-shelves');
	});

	test('an imported product page renders with its specs', async ({ page }) => {
		await page.goto('/products/convection-ovens/bakerlux-classic/blc-113/');

		await expect(page.getByRole('heading', { level: 1 })).toContainText(/Convection Oven/i);
		await expect(page.locator('body')).toContainText('BLC-113');

		// Catalogue only: nothing may be purchasable.
		await expect(page.locator('.single_add_to_cart_button')).toHaveCount(0);
		await expect(page.locator('form.cart')).toHaveCount(0);
	});

	test('re-importing the same workbook writes nothing', async ({ page }) => {
		const before = productCount();

		await page.goto(IMPORT_PATH);
		await page.setInputFiles('input[type="file"]', CORRECTED);

		await expect(page.locator('.cadco-import-counts')).toBeVisible();

		// 236 unchanged, nothing to create, nothing to update.
		const counts = await page.locator('.cadco-import-counts').innerText();
		expect(counts).toMatch(/236[\s\S]*unchanged/i);

		expect(productCount()).toBe(before);
	});

	// Task 15: the plan preview's "N to update" used to be the whole story —
	// an operator approving an update run (every import after the first one)
	// could not see what would actually change. This proves the real diff
	// end to end: a real cell changed in a copy of the real corrected
	// workbook, diffed against the real snapshot the earlier full import
	// wrote for that exact product, rendered as a real table row.
	test('an updated cell is shown as a real per-field diff in the update table', async ({ page }) => {
		const model  = 'BLC-113';
		const column = 'Weight';

		// The exact "before" value the diff table's "Was" column must show —
		// read back from the stored snapshot rather than assumed, so this
		// test does not depend on the workbook's current contents matching
		// whatever value a previous author of this test happened to expect.
		const snapshotJson = wp(['eval', `
			$id = wc_get_product_id_by_sku('${model}');
			echo get_post_meta($id, '_cadco_import_snapshot', true);
		`]);
		const before = JSON.parse(snapshotJson)[column];
		expect(before).toBeTruthy();

		const after = String(Number(before) + 1);
		expect(after).not.toBe(before);

		const fixture = modifyWorkbookCell(CORRECTED, 'CONVECTION OVENS', model, column, after);

		await page.goto(IMPORT_PATH);
		await page.setInputFiles('input[type="file"]', fixture);

		await expect(page.locator('.cadco-import-counts')).toContainText(/1[\s\S]*to update/i);
		await expect(page.getByRole('heading', { name: /Products to update/i })).toBeVisible();

		const row = page.locator('table.widefat').filter({ hasText: model }).locator('tbody tr').first();
		await expect(row).toContainText(model);
		await expect(row).toContainText(column);
		await expect(row).toContainText(before);
		await expect(row).toContainText(after);

		// Preview only — nothing was applied.
		expect(productCount()).toBe(236);
	});

	test('a non-xlsx upload is refused', async ({ page }) => {
		await page.goto(IMPORT_PATH);
		await page.setInputFiles('input[type="file"]', {
			name: 'not-a-workbook.txt',
			mimeType: 'text/plain',
			buffer: Buffer.from('Model #,UPC#\nBLC-113,654796-52113-5\n'),
		});

		await expect(page.locator('.notice-error')).toContainText(/not an \.xlsx workbook/i);
	});

	// Task 9: end-to-end coverage of the wizard shell (Task 5), the Review
	// screen's Categories section (Tasks 5-7), the History tab and restore
	// (Task 8). Everything above this point predates those tasks.

	// Gap: nobody had clicked through the change navigator itself — every
	// prior Review-screen test reaches its one section of interest directly,
	// with no assertion that the sidebar's links actually work, or that a
	// section with something to show is a real, clickable link rather than
	// the inert <span> a zero-count section renders as (CADCO_Import_View::navigator()).
	// sections-v1/sections-v2 (tests/e2e/fixtures/build.php) exist purely to
	// give every one of the eight sections a genuine non-zero count in one
	// pass, without the cost of a real 236-row apply.
	test('every review section is reachable from the sidebar and renders its table', async ({ page }) => {
		resetCatalogue();

		try {
			const v1 = buildFixture('sections-v1');

			await page.goto(IMPORT_PATH);
			await page.setInputFiles('input[type="file"]', v1);
			await expect(page.locator('#cadco-import-apply')).toBeVisible();
			await page.click('#cadco-import-apply');
			await expect(page.locator('.cadco-import-status')).toContainText(/Done/i, { timeout: 30000 });

			// The rename half of sections-v2's plan: an existing product,
			// seeded directly, whose UPC the workbook's row 'E2E-SECT-RENAME-NEW-1'
			// matches under a different model number.
			seedRenameSource('E2E-SECT-RENAME-OLD-1', '654796-99080-3', 'Sections Test — before rename');

			const v2 = buildFixture('sections-v2');

			await page.goto(IMPORT_PATH);
			await page.setInputFiles('input[type="file"]', v2);
			await expect(page.locator('#cadco-import-apply')).toBeVisible();

			const sections = ['workbook', 'categories', 'products', 'updates', 'renames', 'removals', 'cleaned-up', 'redirects'];

			for (const id of sections) {
				// #cadco-nav-<id> only exists on the live <a> variant
				// (CADCO_Import_View::navigator()) — a muted section renders
				// an id-less <span> instead, so finding this at all is
				// itself proof the section is reachable, not merely present.
				const link = page.locator(`#cadco-nav-${id}`);
				await expect(link).toHaveCount(1);

				await link.click();

				const target = page.locator(`#cadco-section-${id}`);
				await expect(target).toBeVisible();
				// The click moved real DOM focus onto the section
				// (assets/js/import-admin.js's navigator click handler) —
				// the accessibility contract design spec §6.1 calls for.
				await expect(target).toBeFocused();
				await expect(link).toHaveAttribute('aria-current', 'true');
			}

			// Each section's content is a real fact from this exact plan,
			// not just "a table exists somewhere on the page".
			await expect(page.locator('#cadco-section-categories')).toContainText('Griddles');
			await expect(page.locator('#cadco-section-products')).toContainText('E2E-SECT-NEW2-1');
			await expect(page.locator('#cadco-section-updates')).toContainText('E2E-SECT-KEEP-1');
			await expect(page.locator('#cadco-section-updates')).toContainText('Weight');
			await expect(page.locator('#cadco-section-renames')).toContainText('E2E-SECT-RENAME-OLD-1');
			await expect(page.locator('#cadco-section-renames')).toContainText('E2E-SECT-RENAME-NEW-1');
			await expect(page.locator('#cadco-section-removals')).toContainText('E2E-SECT-GONE-1');
			await expect(page.locator('#cadco-section-cleaned-up')).toContainText('Sections Test');
			// legacy_redirect_forecast() reads the *workbook's* 'Website URL'
			// column, not the database — sections-v1's product has none yet,
			// so redirect_map() (existing, DB-derived redirects) is still
			// empty and the table itself doesn't render; the forecast
			// paragraph is what this workbook's one legacy URL actually
			// produces.
			// toHaveText(), not toContainText(): the count span holds
			// nothing but the number, and toContainText('1') would still
			// pass against '11', '21' or '100'.
			await expect(page.locator('#cadco-heading-redirects .count')).toHaveText('1');
			await expect(page.locator('#cadco-section-redirects'))
				.toContainText('1 legacy URL will redirect to its new product page once this plan is applied.');
		} finally {
			resetCatalogue();
		}
	});

	// Gap: CADCO_Import_Term_Diff::compare_categories()'s tree-building is
	// unit-tested (TermDiffTest), but nothing had ever rendered it through
	// CADCO_Import_View::category_tree() and checked the actual DOM nesting —
	// a flat list of "parent" and "child" <li>s side by side would satisfy a
	// looser assertion just as well as a real tree, but is not what the
	// design calls for.
	test('the category tree shows a parent with nested children', async ({ page }) => {
		resetCatalogue();

		try {
			const fixture = buildFixture('category-tree');

			await page.goto(IMPORT_PATH);
			await page.setInputFiles('input[type="file"]', fixture);
			await expect(page.locator('#cadco-import-apply')).toBeVisible();

			// The sheet name, title-cased, is the one new parent — both
			// distinct 'Type' values are its children, nested underneath it.
			const parent = page.locator('#cadco-section-categories .cadco-import-term-tree > li')
				.filter({ hasText: 'Convection Ovens' });
			await expect(parent).toHaveCount(1);

			const children = parent.locator('ul > li');
			await expect(children).toHaveCount(2);
			await expect(children.filter({ hasText: 'Ranges' })).toContainText('1 item');
			await expect(children.filter({ hasText: 'Griddles' })).toContainText('1 item');
		} finally {
			resetCatalogue();
		}
	});

	// Gap: PlannerTest and TermDiffTest both prove the in-use *data* is
	// computed correctly; nothing had ever proven CADCO_Import_View::in_use_term_list()
	// actually renders it, or renders it loudly (design spec §7's "the one
	// thing here that needs a person, not a nod"). Two real imports, not one:
	// the warning only exists for a term the SITE already has and still
	// holds a product — a single check-only upload has no such term yet.
	test('the in-use warning appears when a term with products is no longer implied', async ({ page }) => {
		resetCatalogue();

		try {
			const before = buildFixture('in-use-a');

			await page.goto(IMPORT_PATH);
			await page.setInputFiles('input[type="file"]', before);
			await expect(page.locator('#cadco-import-apply')).toBeVisible();
			await page.click('#cadco-import-apply');
			await expect(page.locator('.cadco-import-status')).toContainText(/Done/i, { timeout: 30000 });

			// Re-categorised under 'Fryers' and only ever checked — 'Warmers'
			// still holds the one real product on the site, but this
			// workbook no longer implies it at all.
			const after = buildFixture('in-use-b');

			await page.goto(IMPORT_PATH);
			await page.setInputFiles('input[type="file"]', after);
			await expect(page.locator('#cadco-import-apply')).toBeVisible();

			const inUse = page.locator('#cadco-section-categories .cadco-import-in-use');
			await expect(inUse).toBeVisible();
			await expect(inUse).toContainText(/will NOT be removed/i);
			await expect(inUse).toContainText('Warmers');
			await expect(inUse).toContainText('1 product');

			// Preview only — the second upload never touched the catalogue.
			// 4, not 1: completeSheets() fills the other three canonical
			// sheets with their own default row each (see the trash test
			// above for the same background-row accounting).
			expect(productCount()).toBe(4);
		} finally {
			resetCatalogue();
		}
	});

	// Gap: every prior test either asserted #cadco-import-apply is entirely
	// absent (0 elements, the invalid case) or visible (the clean case) —
	// nothing had ever inspected CADCO_Import_View::cta()'s disabled
	// placeholder itself: its `disabled` attribute, its blocked-state class,
	// or its "Fix N problems" wording, none of which the older assertions
	// could catch regressing.
	test('the CTA is disabled on an invalid workbook and enabled on a clean one', async ({ page }) => {
		await page.goto(IMPORT_PATH);
		await page.setInputFiles('input[type="file"]', SOURCE);

		const blockedCta = page.locator('.cadco-import-cta button');
		await expect(blockedCta).toBeVisible();
		await expect(blockedCta).toBeDisabled();
		await expect(blockedCta).toHaveClass(/cadco-import-cta-blocked/);
		await expect(blockedCta).toContainText(/Fix \d+ problems? to continue/i);
		await expect(blockedCta).not.toHaveAttribute('id', 'cadco-import-apply');

		await page.goto(IMPORT_PATH);
		await page.setInputFiles('input[type="file"]', CORRECTED);

		const applyCta = page.locator('.cadco-import-cta #cadco-import-apply');
		await expect(applyCta).toBeVisible();
		await expect(applyCta).toBeEnabled();
		await expect(applyCta).toContainText(/Apply plan/i);
	});

	// Gap: CADCO_Import_View::history()/history_row() had never been
	// rendered by anything but PHPUnit (which never renders HTML). A run
	// only needs to be *checked*, not applied, to be archived (task brief:
	// "every upload — including one that fails validation — is archived"),
	// so this proves the list end to end off the cheapest possible run.
	test('the History tab lists a run after an import', async ({ page }) => {
		resetCatalogue();

		try {
			const fixture = buildFixture('history-run');
			const filename = path.basename(fixture);

			await page.goto(IMPORT_PATH);
			await page.setInputFiles('input[type="file"]', fixture);
			await expect(page.locator('#cadco-import-apply')).toBeVisible();

			await page.goto(`${IMPORT_PATH}&tab=history`);

			const row = page.locator('table.cadco-import-history tbody tr').filter({ hasText: filename });
			await expect(row).toHaveCount(1);
			await expect(row.locator('td').nth(2)).toContainText(filename);
		} finally {
			resetCatalogue();
		}
	});

	// Gap: CADCO_Import_View::run_result_summary()'s "Not applied — " prefix
	// (fix round 1, finding 1) is unit-tested against the string it returns,
	// never against what actually lands in the DOM, and never side by side
	// with the applied case it exists to be told apart from. A run that was
	// only ever checked must never read as a past-tense fact.
	test('the History Result column distinguishes an unapplied run from a completed one', async ({ page }) => {
		resetCatalogue();

		try {
			const checkedOnly = buildFixture('history-run');
			const checkedName = path.basename(checkedOnly);

			await page.goto(IMPORT_PATH);
			await page.setInputFiles('input[type="file"]', checkedOnly);
			await expect(page.locator('#cadco-import-apply')).toBeVisible();
			// Deliberately never clicked — this run stays "reviewed, not applied".

			const applied = buildFixture('restore-a');
			const appliedName = path.basename(applied);

			await page.goto(IMPORT_PATH);
			await page.setInputFiles('input[type="file"]', applied);
			await expect(page.locator('#cadco-import-apply')).toBeVisible();
			await page.click('#cadco-import-apply');
			await expect(page.locator('.cadco-import-status')).toContainText(/Done/i, { timeout: 30000 });

			await page.goto(`${IMPORT_PATH}&tab=history`);

			// 4 created, not 1: completeSheets() fills the other three
			// canonical sheets with their own default row each, so even
			// this minimal fixture's plan creates 4 products.
			const unappliedRow = page.locator('table.cadco-import-history tbody tr').filter({ hasText: checkedName });
			await expect(unappliedRow).toHaveClass(/cadco-import-history-unapplied/);
			await expect(unappliedRow).toContainText(/Not applied — 4 created/);

			const appliedRow = page.locator('table.cadco-import-history tbody tr').filter({ hasText: appliedName });
			await expect(appliedRow).not.toHaveClass(/cadco-import-history-unapplied/);
			await expect(appliedRow).toContainText('4 created');
			await expect(appliedRow).not.toContainText(/Not applied/);
		} finally {
			resetCatalogue();
		}
	});

	// Gap: assets/js/import-admin.js's label-saving block (task brief step 2)
	// had never run against a real request/response cycle, and nothing had
	// proven the edit outlives the page it was typed on — the one property
	// that actually matters for a label meant to be read back later.
	test('editing a label persists across a reload', async ({ page }) => {
		resetCatalogue();

		try {
			const fixture = buildFixture('history-run');
			const filename = path.basename(fixture);

			await page.goto(IMPORT_PATH);
			await page.setInputFiles('input[type="file"]', fixture);
			await expect(page.locator('#cadco-import-apply')).toBeVisible();

			await page.goto(`${IMPORT_PATH}&tab=history`);

			const row = page.locator('table.cadco-import-history tbody tr').filter({ hasText: filename });
			const label = row.locator('.cadco-import-history-label');
			const status = row.locator('.cadco-import-history-label-status');

			const labelText = `E2E label ${Date.now()}`;
			await label.fill(labelText);
			await label.blur();

			await expect(status).toContainText(/saved/i);

			await page.reload();

			const reloadedRow = page.locator('table.cadco-import-history tbody tr').filter({ hasText: filename });
			await expect(reloadedRow.locator('.cadco-import-history-label')).toHaveValue(labelText);
		} finally {
			resetCatalogue();
		}
	});

	// Gap: CADCO_Import_Admin::handle_restore()/handle_restored_review() and
	// the restore banner (CADCO_Import_View::restore_banner()) had never run
	// end to end — ArchiveTest covers pruning and manifest handling, but
	// nothing had clicked a real "Restore" link and looked at what came back.
	// restore-a/restore-b (build.php) exist so the product a restore is
	// meant to bring back is genuinely trashed by a second, independent
	// import, not merely absent from the very first one.
	test('restoring a run lands on Review and shows the restore banner', async ({ page }) => {
		resetCatalogue();

		try {
			const a = buildFixture('restore-a');
			const aFilename = path.basename(a);

			await page.goto(IMPORT_PATH);
			await page.setInputFiles('input[type="file"]', a);
			await expect(page.locator('#cadco-import-apply')).toBeVisible();
			await page.click('#cadco-import-apply');
			await expect(page.locator('.cadco-import-status')).toContainText(/Done/i, { timeout: 30000 });

			const b = buildFixture('restore-b');

			await page.goto(IMPORT_PATH);
			await page.setInputFiles('input[type="file"]', b);
			await expect(page.locator('#cadco-import-apply')).toBeVisible();
			await page.click('#cadco-import-apply');
			await expect(page.locator('.cadco-import-status')).toContainText(/Done/i, { timeout: 30000 });

			// E2E-RESTORE-1 is now trashed — the state a restore of run 'a'
			// exists to undo.
			expect(trashedProductIdBySku('E2E-RESTORE-1')).not.toBeNull();

			await page.goto(`${IMPORT_PATH}&tab=history`);

			const row = page.locator('table.cadco-import-history tbody tr').filter({ hasText: aFilename });
			await expect(row).toHaveCount(1);
			await row.getByRole('link', { name: /restore/i }).click();

			// Post/redirect/get (fix round 1, finding 2): a successful
			// restore lands here, never back on a bare upload form.
			await expect(page).toHaveURL(/restored_run=/);

			// The banner's selector carries `.inline`, which is what tells
			// wp-admin's own admin.js to leave it exactly where
			// CADCO_Import_View::review() rendered it rather than relocating
			// it to just after the page header (see restore_banner()'s own
			// docblock, fix round 1 finding 6) — asserted here, not just
			// claimed: the class itself, and that the banner still sits
			// immediately before the counts summary review() renders right
			// after it, rather than having drifted anywhere else in the DOM.
			const banner = page.locator('.cadco-import-restore-banner');
			await expect(banner).toBeVisible();
			await expect(banner).toHaveClass(/\binline\b/);
			await expect(banner).toContainText(/Restoring a previous import/i);
			await expect(banner).toContainText(aFilename);
			await expect(banner.locator('xpath=following-sibling::*[1]')).toHaveClass(/cadco-import-counts/);

			// A banner alone proves nothing if the plan behind it is empty —
			// this restore's Review must actually offer bringing the
			// trashed product back. Scoped to the one list item rather than
			// the whole six-item counts summary: this plan also has "1 to
			// trash" (E2E-RESTORE-BG-1, missing from the restored workbook),
			// so an unscoped match against the whole list would pass even if
			// the untrash count itself had regressed to 0.
			await expect(page.locator('.cadco-import-counts li').filter({ hasText: 'to restore' })).toContainText('1');
		} finally {
			resetCatalogue();
		}
	});

	// Gap: nothing, unit or E2E, had ever run a restore all the way through
	// CADCO_Import_Applier's untrash job and checked the resulting post ID.
	// The failure mode this guards against is real and specific: a restore
	// that silently creates a second product instead of reviving the first
	// would still show "1 created" and look successful on screen.
	test('a restore untrashes a removed product in place rather than duplicating it', async ({ page }) => {
		resetCatalogue();

		try {
			const a = buildFixture('restore-a');

			await page.goto(IMPORT_PATH);
			await page.setInputFiles('input[type="file"]', a);
			await expect(page.locator('#cadco-import-apply')).toBeVisible();
			await page.click('#cadco-import-apply');
			await expect(page.locator('.cadco-import-status')).toContainText(/Done/i, { timeout: 30000 });

			const originalId = productIdBySku('E2E-RESTORE-1');
			expect(originalId).not.toBeNull();

			const b = buildFixture('restore-b');

			await page.goto(IMPORT_PATH);
			await page.setInputFiles('input[type="file"]', b);
			await expect(page.locator('#cadco-import-apply')).toBeVisible();
			await page.click('#cadco-import-apply');
			await expect(page.locator('.cadco-import-status')).toContainText(/Done/i, { timeout: 30000 });

			expect(postStatus(originalId)).toBe('trash');

			const aFilename = path.basename(a);

			await page.goto(`${IMPORT_PATH}&tab=history`);
			const row = page.locator('table.cadco-import-history tbody tr').filter({ hasText: aFilename });
			await row.getByRole('link', { name: /restore/i }).click();
			await expect(page).toHaveURL(/restored_run=/);
			await expect(page.locator('.cadco-import-restore-banner')).toBeVisible();

			await page.click('#cadco-import-apply');
			await expect(page.locator('.cadco-import-status')).toContainText(/Done/i, { timeout: 30000 });

			// Same post — untrashed, not re-created under a new ID.
			expect(productIdBySku('E2E-RESTORE-1')).toBe(originalId);
			expect(postStatus(originalId)).toBe('publish');
		} finally {
			resetCatalogue();
		}
	});

	// Gap (fix round 1, finding 2): restore used to be a side-effecting GET —
	// reloading the page WP nonces don't invalidate would silently create
	// another run directory, copy the workbook again, and prune() again.
	// handle_restored_review() is meant to make the landing URL (?restored_run=)
	// purely read-only; nothing had ever actually reloaded it and checked
	// that no second run appeared on disk.
	test('reloading the restored review does not create another run', async ({ page }) => {
		resetCatalogue();

		try {
			const a = buildFixture('restore-a');

			await page.goto(IMPORT_PATH);
			await page.setInputFiles('input[type="file"]', a);
			await expect(page.locator('#cadco-import-apply')).toBeVisible();
			await page.click('#cadco-import-apply');
			await expect(page.locator('.cadco-import-status')).toContainText(/Done/i, { timeout: 30000 });

			const b = buildFixture('restore-b');

			await page.goto(IMPORT_PATH);
			await page.setInputFiles('input[type="file"]', b);
			await expect(page.locator('#cadco-import-apply')).toBeVisible();
			await page.click('#cadco-import-apply');
			await expect(page.locator('.cadco-import-status')).toContainText(/Done/i, { timeout: 30000 });

			const aFilename = path.basename(a);

			await page.goto(`${IMPORT_PATH}&tab=history`);
			const row = page.locator('table.cadco-import-history tbody tr').filter({ hasText: aFilename });
			await row.getByRole('link', { name: /restore/i }).click();
			await expect(page).toHaveURL(/restored_run=/);
			await expect(page.locator('.cadco-import-restore-banner')).toBeVisible();

			const before = runDirectoryCount();

			await page.reload();

			await expect(page.locator('.cadco-import-restore-banner')).toBeVisible();
			expect(runDirectoryCount()).toBe(before);
		} finally {
			cleanupUploadRuns();
			resetCatalogue();
		}
	});

	// Gap: `grep -rn "prune(" tests/` (outside comments) turns up nothing —
	// ArchiveTest.php is five tests, all of them on is_valid_run_id(), never
	// on prune() itself. This test is the only prune() coverage in the
	// repository, at any level, so it has to prove more than "some run got
	// deleted": prune()'s one-hour "too young to prune" floor (see its own
	// docblock) means 21 REAL uploads would not prove anything at all —
	// every one would still be within the hour and nothing would ever be
	// deleted — so seedAgedRuns() fabricates 20 backdated-but-otherwise-real
	// run directories instead, and only the 21st (the one this test
	// actually uploads) is a genuine browser request.
	test('retention prunes at 21 runs', async ({ page }) => {
		cleanupUploadRuns();

		try {
			// seedAgedRuns() returns ids newest-first, so the last two are
			// the oldest and second-oldest of the batch.
			const ids = seedAgedRuns(20);
			const oldest = ids[ids.length - 1];
			const nextOldest = ids[ids.length - 2];
			expect(runDirectoryCount()).toBe(20);

			const fixture = buildFixture('history-run');

			await page.goto(IMPORT_PATH);
			await page.setInputFiles('input[type="file"]', fixture);
			await expect(page.locator('#cadco-import-apply')).toBeVisible();

			// Identity, not just cardinality. A bare count of 20 cannot
			// distinguish "the oldest run was pruned" from "some other run
			// was pruned" — an ordering regression in prune()'s rsort()/
			// array_slice() (class-cadco-import-archive.php:304-306) could
			// prune the newest of the 21 instead of the oldest and this
			// test would not notice if it only checked the total.
			expect(runDirectoryExists(oldest)).toBe(false);
			expect(runDirectoryExists(nextOldest)).toBe(true);
			expect(runDirectoryCount()).toBe(20);
		} finally {
			cleanupUploadRuns();
		}
	});

	// Gap: prune()'s $except_run_id parameter (class-cadco-import-archive.php:274,
	// 308-313) exists for exactly one caller — archive_and_track(), passing
	// $restored_from (class-cadco-import-admin.php:655) — and had no test at
	// any level. Without it, restoring the oldest run at the retention cap
	// would prune that same run in the very request that just copied it: the
	// archived copy about to be reviewed would still exist, but the run it
	// was restored FROM would silently vanish. The existing restore tests
	// never catch this because they run with only ~3 run directories on
	// disk, nowhere near the cap where prune() would ever consider deleting
	// anything.
	test("restoring the oldest run at the retention cap survives its own prune()", async ({ page }) => {
		cleanupUploadRuns();

		try {
			// seedAgedRuns() orders newest-first; the last id is the single
			// oldest, and therefore the one this test's restore must spare.
			const ids = seedAgedRuns(20);
			const oldest = ids[ids.length - 1];

			// seedAgedRuns() only ever writes a manifest.json — enough for
			// this run to appear in History, but handle_restore() also
			// reads the archived workbook.xlsx itself before it will
			// restore FROM a run, so this test's one real run needs one.
			const fixture = buildFixture('restore-a');
			plantWorkbookInRun(oldest, fixture);

			await page.goto(`${IMPORT_PATH}&tab=history`);

			// Located by the run id inside the Restore link's own href
			// rather than by filename text — this run's manifest carries a
			// synthetic 'seed-*.xlsx' filename, not this fixture's real one.
			const restoreLink = page.locator(`a[href*="run_id=${oldest}"]`);
			await expect(restoreLink).toHaveCount(1);
			await restoreLink.click();

			await expect(page).toHaveURL(/restored_run=/);
			await expect(page.locator('.cadco-import-restore-banner')).toBeVisible();

			// This restore's own new run is the 21st on disk (20 seeded +
			// 1), which is exactly the request where CADCO_Import_Archive::prune(20, $restored_from)
			// runs — $restored_from here is 'oldest', the single oldest run
			// on disk and, without the exemption, precisely what an
			// un-exempted prune(20) would delete.
			expect(runDirectoryExists(oldest)).toBe(true);
		} finally {
			cleanupUploadRuns();
		}
	});
});
