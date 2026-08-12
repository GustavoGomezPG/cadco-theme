const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');

const THEME = path.resolve(__dirname, '../..');
const RESTORE_FILE = path.join(__dirname, '.password-restore.json');

/**
 * Undo global-setup.js's password change.
 *
 * The original hash is written back to the `user_pass` column directly via
 * `$wpdb` — never through `wp user update` or `wp_set_password()`, both of
 * which would rehash whatever string they were given and produce a
 * *different* hash than the one the site started with. Runs even if a test
 * failed (Playwright always runs globalTeardown once globalSetup has
 * completed), so an assertion failure partway through the suite can never
 * leave the site's real admin password overwritten with the run's random one.
 */
module.exports = async () => {
	if (!fs.existsSync(RESTORE_FILE)) {
		return;
	}

	const { login, originalHash } = JSON.parse(fs.readFileSync(RESTORE_FILE, 'utf8'));

	execFileSync('wp', [
		'eval',
		`global $wpdb; $wpdb->update($wpdb->users, ['user_pass' => '${originalHash}'], ['user_login' => '${login}']);`,
	], { cwd: THEME });

	fs.unlinkSync(RESTORE_FILE);
};
