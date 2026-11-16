// The admin's media library (C-5.3) on the admin test site: upload progress is announced, a new file is described
// by keyboard, refused files are explained in the focused error summary, the grid / list views are links that say
// which one is shown, and editors publish and delete. No axe violations in light and dark mode; reflows at 320px.
import { expect, test } from '@playwright/test';
import { ADMIN_SITES, expectNoViolations, signIn } from './support/admin-site';

const ADMIN_SITE = ADMIN_SITES.media;

// A 1 × 1 PNG.
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64');

test.beforeEach(async ({ request }) => {
  await request.get(`${ADMIN_SITE}/_test/reset`);
});

async function upload(page: import('@playwright/test').Page, name = 'dusk.png'): Promise<void> {
  await page.getByLabel('File to upload').setInputFiles({ name, mimeType: 'image/png', buffer: PNG });
  await page.getByRole('button', { name: 'Upload', exact: true }).click();
}

test('a contributor uploads a picture, hears the progress, and describes it by keyboard', async ({ page }) => {
  await signIn(page, ADMIN_SITE, 'cleo@example.org');
  await page.getByRole('navigation', { name: 'Admin' }).getByRole('link', { name: 'Media' }).click();

  await expect(page).toHaveTitle('Media - Admin - Riverside Library');
  await expect(page.getByText('There are no files yet. Upload one above.')).toBeVisible();
  await expect(page.locator('#media-upload-status')).toHaveAttribute('aria-live', 'polite');
  await expectNoViolations(page);

  // Hold the upload for a moment so the progress can be seen.
  await page.route('**/admin/media/upload', async (route) => {
    await new Promise((resolve) => setTimeout(resolve, 1500));
    await route.continue();
  });
  await upload(page);
  await expect(page.locator('#media-upload-status')).toHaveText(/^(Uploading dusk\.png: \d+%|dusk\.png has arrived\. Checking the file…)$/);
  await expect(page.getByRole('progressbar')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Upload', exact: true })).toBeDisabled();

  await expect(page).toHaveURL(new RegExp(`${ADMIN_SITE}/admin/media/\\d+$`));
  await expect(page.getByRole('status')).toHaveText('dusk.png has been uploaded. Describe it below, so everyone can understand it.');
  await expect(page.getByRole('heading', { level: 1, name: 'dusk.png' })).toBeVisible();
  await expect(page.getByText('This picture has no alt text yet.')).toBeVisible();
  await expect(page.getByLabel('Published')).toHaveCount(0);
  await expectNoViolations(page);

  await page.getByLabel('Alt text (optional)').focus();
  await page.keyboard.type('The sea at dusk');
  await page.keyboard.press('Tab');
  await expect(page.getByLabel('Caption (optional)')).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(page.getByRole('button', { name: 'Save details' })).toBeFocused();
  await page.keyboard.press('Enter');

  await expect(page.getByRole('status')).toHaveText('The details of dusk.png have been saved.');
  await expect(page.getByRole('img', { name: 'The sea at dusk' })).toBeVisible();
  await expectNoViolations(page);
});

test('a refused file is explained in the error summary, which gets focus', async ({ page }) => {
  await signIn(page, ADMIN_SITE, 'cleo@example.org', '/admin/media');
  await page.getByLabel('File to upload').setInputFiles({ name: 'logo.svg', mimeType: 'image/svg+xml', buffer: Buffer.from('<svg xmlns="http://www.w3.org/2000/svg"/>') });
  await page.getByRole('button', { name: 'Upload', exact: true }).click();

  await expect(page).toHaveTitle('1 error: Media - Admin - Riverside Library');
  await expect(page.locator('#upload-errors')).toBeFocused();
  await expect(page.locator('#upload-errors').getByRole('link')).toHaveText(/^This type of file can't be uploaded\./);
  await expect(page.getByLabel('File to upload')).toHaveAttribute('aria-invalid', 'true');
  await expectNoViolations(page);
});

test('files show as a grid or a table, and the views say which is shown', async ({ page }) => {
  await signIn(page, ADMIN_SITE, 'cleo@example.org', '/admin/media');
  await upload(page);
  await page.getByRole('link', { name: 'Back to media' }).click();

  const views = page.getByRole('list', { name: 'Show files as' });
  await expect(views.getByRole('link', { name: 'Grid' })).toHaveAttribute('aria-current', 'true');
  await expect(page.getByRole('link', { name: 'dusk.png' })).toBeVisible();
  await expect(page.getByText('alt text missing')).toBeVisible();
  await expectNoViolations(page);

  await views.getByRole('link', { name: 'List' }).focus();
  await page.keyboard.press('Enter');
  await expect(views.getByRole('link', { name: 'List' })).toHaveAttribute('aria-current', 'true');
  const table = page.getByRole('table', { name: 'Files, newest first (1 in all)' });
  await expect(table.getByRole('rowheader')).toHaveText(['dusk.png']);
  await expect(table.getByRole('cell', { name: 'Missing' })).toBeVisible();
  await expectNoViolations(page);

  await page.setViewportSize({ width: 320, height: 640 });
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
  await expectNoViolations(page);
});

test('an editor publishes a file and deletes it after confirming', async ({ page }) => {
  await signIn(page, ADMIN_SITE, 'bea@example.org', '/admin/media');
  await upload(page);

  await page.getByLabel('Published').check();
  await page.getByRole('button', { name: 'Save details' }).click();
  await expect(page.getByRole('status')).toHaveText('The details of dusk.png have been saved.');
  await expect(page.getByLabel('Published')).toBeChecked();

  await page.getByRole('link', { name: 'Delete this file' }).click();
  await expect(page).toHaveTitle('Delete dusk.png? - Admin - Riverside Library');
  await expectNoViolations(page);
  await page.getByRole('button', { name: 'Delete dusk.png' }).focus();
  await page.keyboard.press('Enter');

  await expect(page).toHaveURL(`${ADMIN_SITE}/admin/media`);
  await expect(page.getByRole('status')).toHaveText('dusk.png has been deleted.');
  await expect(page.getByText('There are no files yet. Upload one above.')).toBeVisible();
});
