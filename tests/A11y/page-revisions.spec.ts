// Revisions (P-2) on the admin test site: after a save, the page's older versions are listed with who and when, a
// version is compared with the one before it (every change said in words, so it reads the same in forced colours and
// with no styles at all) and an older version is restored by keyboard as a new version, which is announced and
// publishes nothing. No axe violations in light and dark mode, in forced colours and at 320px.
import { expect, test } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { ADMIN_SITES, expectNoViolations, signIn } from './support/admin-site';

const SITE = ADMIN_SITES.revisions;

test.beforeEach(async ({ request }) => {
  await request.get(`${SITE}/_test/reset`);
});

test('older versions are compared in words and restored by keyboard without publishing', async ({ page }) => {
  await signIn(page, SITE, 'bea@example.org', '/admin/pages');
  await page.getByRole('link', { name: 'Volunteer with us' }).click();
  await page.getByLabel('Summary (optional)').fill('Help us run the summer reading challenge.');
  await page.getByRole('button', { name: 'Save page' }).first().click();
  await expect(page.getByRole('status')).toHaveText('Saved “Volunteer with us”.');

  await page.getByRole('link', { name: 'Older versions' }).focus();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('heading', { level: 1, name: 'Versions of “Volunteer with us”' })).toBeVisible();
  const table = page.getByRole('table', { name: 'Versions, newest first' });
  await expect(table.getByRole('row')).toHaveCount(3);
  await expect(table.getByRole('cell', { name: 'Bea' }).first()).toBeVisible();
  await expectNoViolations(page);

  await table.getByRole('link', { name: 'Version 2' }).focus();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('heading', { level: 1, name: 'Version 2 of “Volunteer with us”' })).toBeVisible();
  const summary = page.locator('.diff__rows dd').first();
  await expect(summary).toHaveText('[added: Help us run the summer reading challenge.]');
  await expect(summary.locator('ins')).toHaveText('Help us run the summer reading challenge.');
  await expectNoViolations(page);

  // In forced colours, and at 320px wide.
  await page.emulateMedia({ forcedColors: 'active' });
  const forced = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
  expect(forced.violations, 'forced colours').toEqual([]);
  await page.emulateMedia({ forcedColors: 'none' });
  await page.setViewportSize({ width: 320, height: 640 });
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow, 'no sideways scrolling at 320px').toBeLessThanOrEqual(0);
  await page.setViewportSize({ width: 1280, height: 720 });

  // With no styles at all, the change still says what it is.
  await page.evaluate(() => document.querySelectorAll('link[rel="stylesheet"], style').forEach((sheet) => sheet.remove()));
  await expect(summary).toHaveText('[added: Help us run the summer reading challenge.]');

  await page.goto(`${SITE}${new URL(page.url()).pathname.replace(/\/2$/, '/1')}`);
  await expect(page.getByRole('heading', { level: 2, name: 'The first version in this language' })).toBeVisible();
  await page.getByRole('button', { name: 'Restore version 1' }).focus();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('heading', { level: 1, name: 'Edit “Volunteer with us”' })).toBeVisible();
  await expect(page.getByRole('status')).toHaveText('Restored version 1 as version 3. Nothing was published: the page is still draft.');
  await expect(page.getByLabel('Summary (optional)')).toHaveValue('');
  await expect(page.getByText('State: Draft')).toBeVisible();
  await expectNoViolations(page);
});
