// Upload limits (M-5b) on the admin test site: an admin reads the server notice, hides it by keyboard and it stays
// hidden when only the number of refused uploads changes; site settings, the account and branding forms state the
// largest file. No axe violations in light and dark mode, in forced colours or at 320 px.
import { expect, test } from '@playwright/test';
import { ADMIN_SITES, expectNoViolations, signIn } from './support/admin-site';

const SITE = ADMIN_SITES.limits;

test.beforeEach(async ({ request }) => {
  await request.get(`${SITE}/_test/reset`);
  expect((await request.get(`${SITE}/_test/upload-refused`)).status()).toBe(204);
});

test('an admin hides the server notice by keyboard until it changes', async ({ page, request }) => {
  await signIn(page, SITE, 'ada@example.org', '/admin');
  const notice = page.getByRole('region', { name: 'Your server’s settings stop some uploads' });
  await expect(notice).toContainText('1 upload was refused by the web server in the last 7 days');
  await expectNoViolations(page);

  await notice.getByRole('button', { name: 'Hide until this changes' }).focus();
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(`${SITE}/admin`);
  await expect(page.getByRole('status')).toContainText('The notice is hidden until the result changes.');
  await expect(page.getByRole('region', { name: 'Your server’s settings stop some uploads' })).toHaveCount(0);
  await expectNoViolations(page);

  expect((await request.get(`${SITE}/_test/upload-refused`)).status()).toBe(204);
  await page.reload();
  await expect(page.getByRole('region', { name: 'Your server’s settings stop some uploads' })).toHaveCount(0);
});

test('the forms say how large a file can be', async ({ page }) => {
  await signIn(page, SITE, 'ada@example.org', '/admin/settings');
  await expect(page.getByText(/^Largest file you can upload: pictures .* The person who runs the server sets these limits\.$/)).toBeVisible();
  await expectNoViolations(page);

  await page.goto(`${SITE}/admin/settings/branding`);
  await expect(page.locator('#logo-file')).toHaveAccessibleDescription(/^Largest file: /);
  await expectNoViolations(page);

  await page.goto(`${SITE}/account`);
  await expect(page.getByLabel('New picture')).toHaveAccessibleDescription(/^A JPEG, PNG or WebP picture, up to .+\. It is cropped/);
});
