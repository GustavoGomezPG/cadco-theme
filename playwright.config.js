const { defineConfig } = require('@playwright/test');

/**
 * The import screen does real work — 236 products of taxonomy and meta writes —
 * so the timeouts here are deliberately generous. A test that fails because it
 * gave up at 30 seconds tells you nothing about the importer.
 */
module.exports = defineConfig({
	testDir: './tests/e2e',
	globalSetup: require.resolve('./tests/e2e/global-setup.js'),
	globalTeardown: require.resolve('./tests/e2e/global-teardown.js'),
	timeout: 180000,
	expect: { timeout: 15000 },
	fullyParallel: false,
	workers: 1,
	retries: 0,
	reporter: [['list']],
	use: {
		baseURL: process.env.CADCO_BASE_URL || 'https://cadco.local',
		ignoreHTTPSErrors: true,
		storageState: 'tests/e2e/.auth.json',
		screenshot: 'only-on-failure',
		trace: 'retain-on-failure',
	},
});
