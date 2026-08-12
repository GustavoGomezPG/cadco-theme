const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const THEME = path.resolve(__dirname, '../..');

// Overridable so the suite runs on any machine, not just the one it was
// developed on. Falls back to the original developer's path for local runs
// that don't set the variable.
const WORKBOOKS = path.resolve(
	process.env.CADCO_WORKBOOKS
		|| '/Users/gustavogomez/Documents/Projects/CADCO/Products Excel Spreadsheet latest'
);

const CORRECTED = path.join(WORKBOOKS, 'Product Index Spreadsheet 2026_Website_CORRECTED.xlsx');
const SOURCE    = path.join(WORKBOOKS, 'Product Index Spreadsheet 2026_Website_.xlsx');

for (const [name, file] of [['CORRECTED', CORRECTED], ['SOURCE', SOURCE]]) {
	if (!fs.existsSync(file)) {
		throw new Error(
			`Workbook fixture '${name}' not found at ${file}. Set CADCO_WORKBOOKS to the ` +
			`directory containing the CADCO product workbooks before running the E2E suite.`
		);
	}
}

/**
 * Run WP-CLI against the site.
 *
 * Note `wp db query` is deliberately never used anywhere in this suite — the
 * `wp` on PATH is a Local wrapper whose PHP path resolves the site socket, but
 * `db query` shells out to the `mysql` client, which does not. Use `wp eval`.
 */
function wp(args) {
	return execFileSync('wp', args, { cwd: THEME, encoding: 'utf8' }).trim();
}

function productCount() {
	return Number(wp(['post', 'list', '--post_type=product', '--post_status=publish', '--format=count']));
}

function trashedCount() {
	return Number(wp(['post', 'list', '--post_type=product', '--post_status=trash', '--format=count']));
}

function termCount(taxonomy) {
	return Number(wp(['term', 'list', taxonomy, '--format=count']));
}

/**
 * Published/trashed product counts plus category/tag/brand term counts, in
 * one `wp eval` rather than up to five separate `wp()` invocations. Each
 * invocation pays a full WordPress bootstrap (~1.9s measured on this
 * machine) before it does anything — a test that used to call
 * productCount(), trashedCount() and termCount() back to back was paying
 * that cost three or more times over for values a single request already
 * has all of.
 *
 * @returns {{published:number,trashed:number,cats:number,tags:number,brands:number}}
 */
function counts() {
	const php = `
		echo json_encode([
			'published' => (int) wp_count_posts('product')->publish,
			'trashed'   => (int) wp_count_posts('product')->trash,
			'cats'      => (int) wp_count_terms('product_cat'),
			'tags'      => (int) wp_count_terms('product_tag'),
			'brands'    => (int) wp_count_terms('product_brand'),
		]);
	`;

	return JSON.parse(wp(['eval', php]));
}

/**
 * Empty the catalogue so a test starts from a known state.
 *
 * One `wp eval` rather than the loop-of-`wp()`-calls this used to be. That
 * loop was not just slow, it was slow enough to break the suite: each `wp()`
 * invocation pays a full WordPress bootstrap (~1.9s measured on this
 * machine), and a real import leaves roughly 35 categories + 26 tags + 6
 * brands — around 67 terms, deleted one WP-CLI process at a time, so a
 * single reset cost over two minutes of pure process startup before any
 * test work happened. Called from beforeAll, afterAll and every test's own
 * cleanup, that blew the suite's 180s-per-test budget and made unrelated
 * *later* tests fail waiting on a locator, which looked like a product bug
 * and was not one.
 *
 * Products are force-deleted (wp_delete_post($id, true)) rather than
 * trashed: a trashed product would be excluded from the importer's "current
 * products" query and silently recreated, which would make the next
 * assertion lie. Both 'any' and 'trash' status are swept — WordPress's
 * 'any' explicitly excludes 'trash' and 'auto-draft' (core behaviour), so a
 * single-status query would leave every trashed product behind to collide
 * by SKU with a fresh one in the next run.
 *
 * Also clears the `pa_*` attribute taxonomies the importer creates
 * (wc_delete_attribute()) — the previous version of this function never
 * touched them, so 11 attribute taxonomies and ~72 terms survived every
 * single run.
 */
function resetCatalogue() {
	const php = `
		foreach (['any', 'trash'] as $status) {
			foreach (get_posts(['post_type' => 'product', 'post_status' => $status, 'numberposts' => -1, 'fields' => 'ids']) as $id) {
				wp_delete_post($id, true);
			}
		}
		foreach (['product_cat', 'product_tag', 'product_brand'] as $taxonomy) {
			foreach (get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]) as $term) {
				if ($term->slug !== 'uncategorized') {
					wp_delete_term($term->term_id, $taxonomy);
				}
			}
		}
		foreach (wc_get_attribute_taxonomies() as $attribute) {
			wc_delete_attribute($attribute->attribute_id);
		}
		foreach (['cadco_import_taxonomy_reset', 'cadco_import_redirects', 'cadco_flush_category_rules'] as $option) {
			delete_option($option);
		}
	`;

	wp(['eval', php]);
}

/**
 * Delete every run archive the suite leaves under
 * wp-content/uploads/cadco-imports/, plus the `cadco_import_run_{user}`
 * transient that points at them. The importer only prunes runs itself down
 * to the newest 20 (see CADCO_Import_Archive::prune()) and never
 * garbage-collects the transient at all outside of a completed apply run,
 * so a suite run that uploads a workbook a dozen times would otherwise
 * leave a dozen fresh directories — real catalogue data, one guessable
 * timestamp away from being the only trace an operator has — and one
 * dangling transient behind.
 * index.php and .htaccess are left in place; they guard the directory and
 * are re-created on demand anyway.
 */
function cleanupUploadRuns() {
	const php = [
		"$dir = trailingslashit(wp_upload_dir()['basedir']) . 'cadco-imports';",
		'if (is_dir($dir)) {',
		'  foreach (scandir($dir) as $entry) {',
		'    if ($entry === "." || $entry === ".." || $entry === "index.php" || $entry === ".htaccess") { continue; }',
		'    $path = $dir . "/" . $entry;',
		'    if (!is_dir($path) || is_link($path)) { continue; }',
		'    foreach (scandir($path) as $file) {',
		'      if ($file !== "." && $file !== "..") { @unlink($path . "/" . $file); }',
		'    }',
		'    @rmdir($path);',
		'  }',
		'}',
		'foreach (get_users(["fields" => "ID"]) as $uid) { delete_transient("cadco_import_run_" . $uid); }',
		'echo "cleaned";',
	].join("\n");

	wp(['eval', php]);
}

/**
 * Build a small, purpose-built workbook via tests/e2e/fixtures/build.php
 * (which itself reuses tests/fixtures/FixtureBuilder.php, the same generator
 * the PHP unit suite trusts). Written to a temp file outside the repo so
 * nothing generated needs to be gitignored.
 *
 * @param {'xss'|'xss-review'|'rename'} scenario
 * @returns {string} absolute path to the generated .xlsx
 */
function buildFixture(scenario) {
	const out = path.join(os.tmpdir(), `cadco-e2e-${scenario}-${Date.now()}-${process.pid}.xlsx`);

	wp(['eval-file', 'tests/e2e/fixtures/build.php', scenario, out]);

	return out;
}

/**
 * Copy a real workbook and change exactly one cell in it via
 * tests/e2e/fixtures/modify-cell.php + PhpSpreadsheet, locating the cell by
 * header text and by the value in 'Model #' — never by position. Used to
 * exercise the per-field update diff against a real snapshot written by a
 * real import of the real corrected workbook, which a small synthetic
 * fixture cannot prove anything about.
 *
 * @returns {string} absolute path to the modified copy
 */
function modifyWorkbookCell(source, sheet, model, column, newValue) {
	const out = path.join(os.tmpdir(), `cadco-e2e-modified-${Date.now()}-${process.pid}.xlsx`);

	wp(['eval-file', 'tests/e2e/fixtures/modify-cell.php', source, out, sheet, model, column, newValue]);

	return out;
}

/**
 * Seed the "before" side of a rename directly in the database, bypassing the
 * importer entirely — the fixture built with buildFixture('rename') carries a
 * row whose UPC matches this product's, so the planner offers a rename rather
 * than a plain create.
 *
 * @returns {number} the seeded product's post ID
 */
function seedRenameSource() {
	const php = `
		$p = new WC_Product_Simple();
		$p->set_name('Rename Test — before');
		$p->set_sku('E2E-RENAME-OLD-1');
		$p->set_slug('e2e-rename-old-1');
		$p->set_status('publish');
		$p->set_catalog_visibility('visible');
		$p->set_global_unique_id('654796-99020-1');
		echo $p->save();
	`;

	return Number(wp(['eval', php]));
}

function productIdBySku(sku) {
	const id = Number(wp(['eval', `echo (int) wc_get_product_id_by_sku('${sku}');`]));

	return id > 0 ? id : null;
}

function permalinkFor(postId) {
	return wp(['eval', `echo get_permalink(${Number(postId)});`]);
}

/**
 * Find a product by SKU among trashed posts specifically, via a direct meta
 * lookup rather than wc_get_product_id_by_sku() — that helper is not
 * guaranteed to honour post_status, and the whole point of this lookup is to
 * prove the post really is in the 'trash' status rather than merely being
 * unfindable (which a hard delete would also produce).
 */
function trashedProductIdBySku(sku) {
	const id = wp([
		'post', 'list', '--post_type=product', '--post_status=trash',
		'--meta_key=_sku', `--meta_value=${sku}`, '--field=ID', '--format=csv',
	]).trim();

	return id ? Number(id) : null;
}

/**
 * `wp post get` rather than `wp post list --post__in=...` — deliberately.
 * `post list` runs a WP_Query, and WP_Query's `post_status=any` explicitly
 * excludes 'trash' and 'auto-draft' (core behaviour), so a status filter
 * permissive enough to include every real status doesn't exist for that
 * command. `post get` fetches the single post directly via get_post(),
 * which has no status filtering at all — exactly what a lookup meant to
 * report the true current status, whatever it is, needs.
 */
function postStatus(postId) {
	return wp(['post', 'get', String(Number(postId)), '--field=post_status']).trim();
}

/**
 * Temporarily remove a capability from the administrator role, run `fn`, and
 * restore it afterwards even if `fn` throws — the restoration must never be
 * skipped, or every later test would silently start running as an
 * under-privileged admin.
 */
async function withoutCapability(capability, fn) {
	wp(['eval', `get_role('administrator')->remove_cap('${capability}');`]);

	try {
		await fn();
	} finally {
		wp(['eval', `get_role('administrator')->add_cap('${capability}');`]);
	}
}

const IMPORT_PATH = '/wp-admin/edit.php?post_type=product&page=cadco-import';

module.exports = {
	wp,
	productCount,
	trashedCount,
	termCount,
	counts,
	resetCatalogue,
	cleanupUploadRuns,
	buildFixture,
	modifyWorkbookCell,
	seedRenameSource,
	productIdBySku,
	trashedProductIdBySku,
	postStatus,
	permalinkFor,
	withoutCapability,
	CORRECTED,
	SOURCE,
	IMPORT_PATH,
};
