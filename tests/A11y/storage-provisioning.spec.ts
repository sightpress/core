// Cloud storage set-up (M-3) on the admin test site: an admin chooses a provider by keyboard and reads what will be
// created, where, and that a third party is contacted. The spec never confirms, so no provider is called. No axe
// violations in light and dark mode, in forced colours or at 320 px, and it works without JavaScript.
import { expect, test } from '@playwright/test';
import { ADMIN_SITES, expectNoViolations, signIn } from './support/admin-site';

const SITE = ADMIN_SITES.provisioning;
const PATH = '/admin/settings/storage';

test.beforeEach(async ({ request }) => {
  await request.get(`${SITE}/_test/reset`);
});

test('an admin reads the plan by keyboard before anything is set up', async ({ page }) => {
  await signIn(page, SITE, 'ada@example.org', PATH);
  await expect(page.getByRole('group', { name: 'Set up cloud storage' })).toBeVisible();
  await expectNoViolations(page);

  await page.getByRole('radio', { name: 'Amazon Web Services' }).focus();
  await expect(page.getByRole('radio', { name: 'Amazon Web Services' })).toBeChecked();
  await page.keyboard.press('Tab');
  await expect(page.getByRole('button', { name: 'Show the plan' })).toBeFocused();
  await page.keyboard.press('Enter');

  await expect(page.getByRole('heading', { level: 2, name: 'What will be set up' })).toBeVisible();
  await expect(page.getByText('a third party')).toBeVisible();
  await expect(page.getByText('international transfer')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Set up cloud storage' })).toBeVisible();
  await expectNoViolations(page);

  await page.getByRole('link', { name: 'Cancel' }).focus();
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(`${SITE}${PATH}`);
  await expect(page.getByRole('button', { name: 'Show the plan' })).toBeVisible();
});

test('the plan passes axe in dark mode, forced colours and at 320 px', async ({ page }) => {
  await signIn(page, SITE, 'ada@example.org', PATH);
  await page.getByRole('button', { name: 'Show the plan' }).click();
  await expect(page.getByRole('heading', { level: 2, name: 'What will be set up' })).toBeVisible();
  await page.emulateMedia({ colorScheme: 'dark' });
  await expectNoViolations(page);
  await page.emulateMedia({ colorScheme: 'light', forcedColors: 'active' });
  await expectNoViolations(page);
  await page.emulateMedia({ forcedColors: 'none' });
  await page.setViewportSize({ width: 320, height: 640 });
  await expectNoViolations(page);
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
  expect(overflow).toBe(false);
});

test.describe('without JavaScript', () => {
  test.use({ javaScriptEnabled: false });

  test('the plan is shown with a plain form', async ({ page }) => {
    await signIn(page, SITE, 'ada@example.org', PATH);
    await page.getByRole('button', { name: 'Show the plan' }).click();
    await expect(page.getByRole('heading', { level: 2, name: 'What will be set up' })).toBeVisible();
  });
});
