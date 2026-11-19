// Features (F-2) on the admin test site, which has two optional modules: an admin turns "Events" off by keyboard
// through the confirmation page, comes back with focus on its heading and the result in the live region, its pages
// answer "not found", and turns it on again; the same works without JavaScript. No axe violations in light and dark
// mode, in forced colours or at 320 px.
import { expect, test } from '@playwright/test';
import { ADMIN_SITES, expectNoViolations, signIn } from './support/admin-site';

const SITE = ADMIN_SITES.features;

test.beforeEach(async ({ request }) => {
  await request.get(`${SITE}/_test/reset`);
});

test('an admin turns a feature off by keyboard, after confirming, and on again', async ({ page, request }) => {
  await signIn(page, SITE, 'ada@example.org', '/admin/features');
  await expect(page).toHaveTitle('Features - Admin - Riverside Library');
  await expect(page.getByRole('navigation', { name: 'Admin' }).getByRole('link', { name: 'Features' })).toHaveAttribute('aria-current', 'page');
  await expect(page.getByRole('heading', { level: 2, name: 'Events' })).toBeVisible();
  await expect(page.locator('section', { has: page.getByRole('heading', { level: 2, name: 'Events' }) }).getByText('Status: On', { exact: true })).toBeVisible();
  await expectNoViolations(page);
  expect((await request.get(`${SITE}/events`)).status()).toBe(200);

  await page.getByRole('button', { name: 'Turn off Events' }).focus();
  await page.keyboard.press('Enter');
  await expect(page).toHaveTitle('Turn off Events? - Admin - Riverside Library');
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Turn off Events?');
  await expectNoViolations(page);

  await page.getByRole('button', { name: 'Turn off Events' }).focus();
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(`${SITE}/admin/features#feature-acme-events`);
  await expect(page.getByRole('heading', { level: 2, name: 'Events' })).toBeFocused();
  await expect(page.getByRole('status')).toHaveText('Events is off. Its data is kept: turn it on again to bring it back.');
  await expect(page.getByRole('navigation', { name: 'Admin' }).getByRole('link', { name: 'Events' })).toHaveCount(0);
  await expectNoViolations(page);
  expect((await request.get(`${SITE}/events`)).status()).toBe(404);

  await page.keyboard.press('Tab');
  await expect(page.getByRole('button', { name: 'Turn on Events' })).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('heading', { level: 2, name: 'Events' })).toBeFocused();
  await expect(page.getByRole('status')).toHaveText('Events is on.');
  expect((await request.get(`${SITE}/events`)).status()).toBe(200);
});

test('the features screen passes axe in forced colours and at 320 px', async ({ page }) => {
  await signIn(page, SITE, 'ada@example.org', '/admin/features');
  await page.emulateMedia({ forcedColors: 'active' });
  await expectNoViolations(page);
  await page.emulateMedia({ forcedColors: 'none' });
  await page.setViewportSize({ width: 320, height: 640 });
  await expectNoViolations(page);
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
  expect(overflow).toBe(false);
});

test.describe('without JavaScript', () => {
  test.use({ javaScriptEnabled: false });

  test('turning a feature off and deleting its data work with plain forms', async ({ page }) => {
    await signIn(page, SITE, 'ada@example.org', '/admin/features');
    await page.getByRole('button', { name: 'Turn off Events' }).click();
    await page.getByRole('button', { name: 'Turn off Events' }).click();
    await expect(page).toHaveURL(`${SITE}/admin/features#feature-acme-events`);
    await expect(page.getByRole('status')).toHaveText('Events is off. Its data is kept: turn it on again to bring it back.');

    await page.getByRole('link', { name: 'Delete the data of Events' }).click();
    await expect(page).toHaveTitle('Delete the data of Events? - Admin - Riverside Library');
    await page.getByRole('button', { name: 'Delete the data of Events' }).click();
    await expect(page.getByRole('status')).toHaveText('Deleted the data of Events. Turning it on again starts afresh.');
    await expect(page.getByRole('link', { name: 'Delete the data of Events' })).toHaveCount(0);
  });
});
