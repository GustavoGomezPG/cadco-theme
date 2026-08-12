const { chromium } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const THEME = path.resolve(__dirname, '../..');

/**
 * Log in once and save the session, so no test spends time on wp-login.
 *
 * The admin user is resolved through WP-CLI rather than hardcoded, and its
 * password is reset to a known value for the run. That keeps the suite working
 * on any developer's machine without a shared secret in the repo.
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

	execFileSync('wp', ['user', 'update', login, '--user_pass=cadco-e2e-password'], { cwd: THEME });

	const browser = await chromium.launch();
	const page = await browser.newPage({ ignoreHTTPSErrors: true });

	await page.goto(`${baseURL}/wp-login.php`);
	await page.fill('#user_login', login);
	await page.fill('#user_pass', 'cadco-e2e-password');
	await page.click('#wp-submit');
	await page.waitForURL(/wp-admin/);

	await page.context().storageState({ path: path.join(__dirname, '.auth.json') });
	await browser.close();
};
