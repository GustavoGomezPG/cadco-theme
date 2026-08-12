const { test, expect } = require('@playwright/test');
const {
	productCount, trashedCount, termCount, resetCatalogue, cleanupUploadRuns,
	buildFixture, seedRenameSource, productIdBySku, trashedProductIdBySku, postStatus,
	permalinkFor, withoutCapability,
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
		await expect(page.locator('input[type="file"]')).toBeVisible();
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
		await page.getByRole('button', { name: /check workbook/i }).click();

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

		expect(productCount()).toBe(before);
		expect(trashedCount()).toBe(0);
	});

	test('a clean workbook previews a plan without writing anything', async ({ page }) => {
		const before = productCount();

		await page.goto(IMPORT_PATH);
		await page.setInputFiles('input[type="file"]', CORRECTED);
		await page.getByRole('button', { name: /check workbook/i }).click();

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
		await page.getByRole('button', { name: /check workbook/i }).click();
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
		await page.getByRole('button', { name: /check workbook/i }).click();
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
		await page.getByRole('button', { name: /check workbook/i }).click();
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
			await page.getByRole('button', { name: /check workbook/i }).click();

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
			await page.getByRole('button', { name: /check workbook/i }).click();
			await expect(page.locator('#cadco-import-apply')).toBeVisible();

			await page.click('#cadco-import-apply');
			await expect(page.locator('.cadco-import-status')).toContainText(/Done/i, { timeout: 30000 });

			expect(productCount()).toBe(5);
			expect(trashedCount()).toBe(0);

			// Step 2: re-import with the second target row gone (the three
			// background rows are byte-identical, so they hash the same and
			// are skipped, not updated). The plan must offer exactly one
			// trash for the product the workbook no longer lists.
			const reduced = buildFixture('trash-reduced');

			await page.goto(IMPORT_PATH);
			await page.setInputFiles('input[type="file"]', reduced);
			await page.getByRole('button', { name: /check workbook/i }).click();
			await expect(page.locator('.cadco-import-counts')).toBeVisible();
			await expect(page.locator('.cadco-import-counts')).toContainText(/1[\s\S]*to trash/i);

			// Step 3: apply. Four products remain published (one target row
			// plus the three untouched background rows); the fifth is trashed.
			await page.click('#cadco-import-apply');
			await expect(page.locator('.cadco-import-status')).toContainText(/Done/i, { timeout: 30000 });

			expect(productCount()).toBe(4);
			expect(trashedCount()).toBe(1);

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
		await page.getByRole('button', { name: /check workbook/i }).click();
		await expect(page.locator('#cadco-import-apply')).toBeVisible();

		await page.click('#cadco-import-apply');

		// Progress must actually move — a single blocking request would jump
		// straight from 0 to done, or time out.
		const status = page.locator('.cadco-import-status');
		await expect(status).toContainText(/\d+ \/ \d+/, { timeout: 30000 });

		await expect(status).toContainText(/Done/i, { timeout: 240000 });

		expect(productCount()).toBe(236);
		expect(termCount('product_tag')).toBe(26);
		expect(termCount('product_brand')).toBe(6);
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
		await page.getByRole('button', { name: /check workbook/i }).click();

		await expect(page.locator('.cadco-import-counts')).toBeVisible();

		// 236 unchanged, nothing to create, nothing to update.
		const counts = await page.locator('.cadco-import-counts').innerText();
		expect(counts).toMatch(/236[\s\S]*unchanged/i);

		expect(productCount()).toBe(before);
	});

	test('a non-xlsx upload is refused', async ({ page }) => {
		await page.goto(IMPORT_PATH);
		await page.setInputFiles('input[type="file"]', {
			name: 'not-a-workbook.txt',
			mimeType: 'text/plain',
			buffer: Buffer.from('Model #,UPC#\nBLC-113,654796-52113-5\n'),
		});
		await page.getByRole('button', { name: /check workbook/i }).click();

		await expect(page.locator('.notice-error')).toContainText(/not an \.xlsx workbook/i);
	});
});
