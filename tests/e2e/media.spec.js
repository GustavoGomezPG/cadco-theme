/**
 * End-to-end coverage of the media stage's part of Products -> Import.
 *
 * Builds its own small workbook — every canonical sheet with one row, the
 * first carrying a hyperlinked image cell, a folder link and a PC path — so it
 * asserts exact figures without depending on CADCO's current file. Nothing is
 * applied for real: the one apply test intercepts the AJAX call, as the
 * product failure-list test in import.spec.js does, so this file neither
 * downloads anything nor touches the catalogue.
 */
const { test, expect } = require('@playwright/test');
const { wp, cleanupUploadRuns, IMPORT_PATH } = require('./helpers');

const FOLDER = 'https://cadcoltd232.sharepoint.com/:f:/s/CADCOMediaLibrary/EopFolderToken?e=abc';
const LOCAL  = 'file:///C:/Users/someone/manual.pdf';
const IMAGE  = '../../../:i:/r/sites/CADCOMediaLibrary/Shared%20Documents/Forms/AllItems.aspx?id=%2Fa.jpg&share=cQe2eImageToken';
const SPEC   = 'https://cadcoltd232.sharepoint.com/:b:/s/CADCOMediaLibrary/IQe2eSpecToken?e=x';

/**
 * A workbook whose first sheet's row has media links of every kind the
 * review must handle: a hyperlinked relative image, a spec sheet, a folder
 * link and a PC path in the manual cell.
 */
function mediaWorkbook() {
	const out = require('node:path').join(require('node:os').tmpdir(), `cadco-e2e-media-${Date.now()}.xlsx`);

	const php = `
		require_once get_stylesheet_directory() . '/vendor/autoload.php';
		require_once get_stylesheet_directory() . '/tests/fixtures/FixtureBuilder.php';
		$sheets = CADCO\\Tests\\Fixtures\\FixtureBuilder::completeSheets();
		$first  = array_key_first($sheets);
		$sheets[$first][0] = CADCO\\Tests\\Fixtures\\FixtureBuilder::row([
			'Model #'        => 'E2E-MEDIA-1',
			'UPC#'           => '654796-90100-1',
			'Images URL'     => ['text' => 'e2e.jpg', 'link' => ${JSON.stringify(IMAGE)}],
			'Spec Sheet URL' => ${JSON.stringify(SPEC)},
			'Manual URL'     => ${JSON.stringify(FOLDER + '\n' + LOCAL)},
		]);
		CADCO\\Tests\\Fixtures\\FixtureBuilder::write(${JSON.stringify(out)}, $sheets);
	`;

	wp(['eval', php]);

	return out;
}

test.describe('Product import — media', () => {
	let workbook;

	test.beforeAll(() => { workbook = mediaWorkbook(); });
	test.afterAll(() => cleanupUploadRuns());

	test('review shows what the media stage will download and the links it cannot use', async ({ page }) => {
		await page.goto(IMPORT_PATH);
		await page.setInputFiles('input[type="file"]', workbook);
		await expect(page.locator('#cadco-import-apply')).toBeVisible();

		const nav = page.locator('#cadco-nav-media');
		await expect(nav).toBeVisible();
		await nav.click();

		const section = page.locator('#cadco-section-media');
		await expect(section).toBeVisible();

		// The image (relative, completed with the tenant) and the spec sheet
		// are both new files.
		await expect(section.locator('.cadco-import-media-summary')).toContainText('2 to download');

		// The folder and the PC path are each a notice with the row and fix.
		const notices = section.locator('table').last().locator('tbody tr');
		await expect(notices).toHaveCount(2);
		await expect(notices.nth(0)).toContainText('Manual URL');
		await expect(notices.nth(0)).toContainText('folder');
		await expect(notices.nth(1)).toContainText('computer');

		await expect(section.locator('#cadco-import-refresh-media')).not.toBeChecked();
	});

	test('the notices download as CSV for CADCO', async ({ page }) => {
		await page.goto(IMPORT_PATH);
		await page.setInputFiles('input[type="file"]', workbook);
		await expect(page.locator('#cadco-import-apply')).toBeVisible();

		const link = page.locator('#cadco-section-media a', { hasText: 'CSV' });
		const href = await link.getAttribute('href');
		const response = await page.request.get(href);

		expect(response.status()).toBe(200);
		expect(response.headers()['content-disposition']).toContain('cadco-media-notices.csv');

		const csv = await response.text();
		expect(csv).toContain('Manual URL');
		expect(csv).toContain('EopFolderToken');
	});

	test('media failures are listed apart from product failures and do not reduce the applied count', async ({ page }) => {
		await page.goto(IMPORT_PATH);
		await page.setInputFiles('input[type="file"]', workbook);
		await expect(page.locator('#cadco-import-apply')).toBeVisible();

		let sent = null;

		await page.route('**/admin-ajax.php', (route) => {
			sent = route.request().postData() || '';

			return route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					success: true,
					data: {
						done: 5,
						total: 5,
						products: 2,
						complete: true,
						log: [
							'Created E2E-MEDIA-1 (#101)',
							'Created FIXTURE-2 (#102)',
							'Downloaded image for E2E-MEDIA-1 (12 KB)',
							'MEDIA FAILED spec-sheet for E2E-MEDIA-1: SharePoint says the file cannot be accessed.',
							'Resources: 0 created, 0 updated, 0 unchanged',
						],
					},
				}),
			});
		});

		await page.check('#cadco-import-refresh-media');
		await page.click('#cadco-import-apply');

		const media = page.locator('#cadco-import-media-failures');
		await expect(media).toBeVisible();
		await expect(media.locator('li')).toHaveCount(1);
		await expect(media.locator('li')).toContainText('cannot be accessed');

		// No product failed, so the product failure box stays hidden, and
		// "applied" counts the two product jobs, not the five in the queue.
		await expect(page.locator('#cadco-import-failures')).toBeHidden();
		const status = page.locator('.cadco-import-status');
		await expect(status).toContainText('2 changes applied');
		await expect(status).toContainText('1 media item');

		expect(sent).toContain('refresh_media');
	});
});
