const { chromium } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');

const THEME = path.resolve(__dirname, '../..');
const RESTORE_FILE = path.join(__dirname, '.password-restore.json');

/**
 * Log in once and save the session, so no test spends time on wp-login.
 *
 * The admin user is resolved through WP-CLI rather than hardcoded. Its
 * password is temporarily overwritten with a fresh, random value generated
 * for this run — never a literal committed to the repo, since
 * `CADCO_BASE_URL` is overridable and a published literal would otherwise
 * downgrade the credential of any site the suite is pointed at. The
 * password's *original* hash is captured first and written to
 * `.password-restore.json` (gitignored) so `global-teardown.js` can put it
 * back, unrehashed, once the run finishes. This is destructive by nature —
 * see the README warning under "Running the E2E suite".
 */
module.exports = async (config) => {
	const baseURL = config.projects[0].use.baseURL;

	const login = execFileSync('wp', ['user', 'list', '--role=administrator', '--field=user_login', '--format=csv'], {
		cwd: THEME,
		encoding: 'utf8',
	}).trim().split('\n')[0];

	if (!login) {
		throw new Error('No administrator account found. Create one before running the E2E suite.');
	}

	// Captured via $wpdb directly rather than `wp user get --field=user_pass`:
	// this must be the exact stored hash, not anything WP-CLI might normalise,
	// so that writing it back in global-teardown.js is a byte-for-byte restore.
	const originalHash = execFileSync('wp', [
		'eval',
		`global $wpdb; echo $wpdb->get_var($wpdb->prepare("SELECT user_pass FROM {$wpdb->users} WHERE user_login = %s", "${login}"));`,
	], { cwd: THEME, encoding: 'utf8' }).trim();

	if (!originalHash) {
		throw new Error(`Could not read the current password hash for '${login}'. Refusing to overwrite it blind.`);
	}

	fs.writeFileSync(RESTORE_FILE, JSON.stringify({ login, originalHash }), 'utf8');

	const password = crypto.randomBytes(24).toString('base64url');

	execFileSync('wp', ['user', 'update', login, `--user_pass=${password}`], { cwd: THEME });

	const browser = await chromium.launch();
	const page = await browser.newPage({ ignoreHTTPSErrors: true });

	await page.goto(`${baseURL}/wp-login.php`);
	await page.fill('#user_login', login);
	await page.fill('#user_pass', password);
	await page.click('#wp-submit');
	await page.waitForURL(/wp-admin/);

	await page.context().storageState({ path: path.join(__dirname, '.auth.json') });
	await browser.close();
};
