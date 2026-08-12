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
 * Empty the catalogue so a test starts from a known state.
 *
 * Products are force-deleted rather than trashed: a trashed product would be
 * excluded from the importer's "current products" query and silently recreated,
 * which would make the next assertion lie.
 *
 * Two passes, not one: WordPress's `post_status=any` explicitly excludes
 * 'trash' and 'auto-draft' (core behaviour, not a WP-CLI quirk) — a single
 * `--post_status=any` query leaves every trashed product behind. That was
 * latent and harmless as long as nothing in the suite ever trashed a
 * product; the trash-path E2E test does, so without this a trashed product
 * from one run survives into the next and collides with a fresh product of
 * the same SKU, which is exactly the kind of cross-run pollution this
 * function exists to prevent.
 */
function resetCatalogue() {
	for (const status of ['any', 'trash']) {
		const ids = wp(['post', 'list', '--post_type=product', `--post_status=${status}`, '--format=ids']);

		if (ids) {
			wp(['post', 'delete', ...ids.split(/\s+/), '--force']);
		}
	}

	for (const taxonomy of ['product_cat', 'product_tag', 'product_brand']) {
		const terms = wp(['term', 'list', taxonomy, '--field=term_id', '--format=csv'])
			.split('\n')
			.filter((line) => line && line !== 'term_id');

		for (const id of terms) {
			try {
				wp(['term', 'delete', taxonomy, id]);
			} catch (e) {
				// The default 'uncategorized' term cannot be deleted; that is fine.
			}
		}
	}

	wp(['option', 'delete', 'cadco_import_taxonomy_reset']);
	wp(['option', 'delete', 'cadco_import_redirects']);
}

/**
 * Delete every run archive the suite leaves under
 * wp-content/uploads/cadco-imports/, plus the `cadco_import_run_{user}`
 * transient that points at them. The importer only garbage-collects the
 * directories itself after 7 days (see
 * CADCO_Import_Admin::garbage_collect_runs()) and never garbage-collects the
 * transient at all outside of a completed apply run, so a suite run that
 * uploads a workbook a dozen times would otherwise leave a dozen fresh
 * directories — real catalogue data, one guessable timestamp away from being
 * the only trace an operator has — and one dangling transient behind.
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
 * @param {'xss'|'rename'} scenario
 * @returns {string} absolute path to the generated .xlsx
 */
function buildFixture(scenario) {
	const out = path.join(os.tmpdir(), `cadco-e2e-${scenario}-${Date.now()}-${process.pid}.xlsx`);

	wp(['eval-file', 'tests/e2e/fixtures/build.php', scenario, out]);

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
	resetCatalogue,
	cleanupUploadRuns,
	buildFixture,
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
