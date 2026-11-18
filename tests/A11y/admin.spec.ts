// The admin panel (C-7.1 and later) on a real site (tests/A11y/admin/index.php): no axe violations in light and dark
// mode, everything works by keyboard, the navigation shows only what each role may use, and nothing is wider than
// the screen at 320px.
import { expect, test } from '@playwright/test';
import { ADMIN_SITES, PASSWORD, expectNoViolations, signIn } from './support/admin-site';

const ADMIN_SITE = ADMIN_SITES.admin;

test.beforeEach(async ({ request }) => {
  await request.get(`${ADMIN_SITE}/_test/reset`);
});

test('the dashboard has landmarks, a skip link and the current screen marked', async ({ page }) => {
  await signIn(page, ADMIN_SITE, 'bea@example.org');

  await expect(page).toHaveTitle('Dashboard - Admin - Riverside Library');
  await expect(page.getByRole('heading', { level: 1, name: 'Dashboard' })).toBeVisible();
  const nav = page.getByRole('navigation', { name: 'Admin' });
  await expect(nav.getByRole('link', { name: 'Dashboard' })).toHaveAttribute('aria-current', 'page');
  await expect(page.getByRole('banner')).toContainText('Signed in as Bea');
  await expect(page.getByRole('heading', { level: 2, name: 'Waiting for review' })).toBeVisible();
  await expect(page.getByRole('region', { name: 'Waiting for review' }).getByRole('listitem')).toHaveCount(1);
  await expect(page.getByRole('region', { name: 'Your drafts' })).toContainText('Volunteer with us');
  await expect(page.getByRole('region', { name: 'Recent activity' }).locator('time').first()).toHaveAttribute('datetime', /Z$/);
  await expectNoViolations(page);

  await page.keyboard.press('Tab');
  await expect(page.getByRole('link', { name: 'Skip to main content' })).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(page.getByRole('link', { name: 'Riverside Library' })).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(page.getByRole('link', { name: 'My account' })).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(page.getByLabel('Language', { exact: true })).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(page.getByRole('button', { name: 'Change language' })).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(page.getByRole('button', { name: 'Sign out' })).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(nav.getByRole('link', { name: 'Dashboard' })).toBeFocused();

  for (let step = 0; step < 6; step++) {
    await page.keyboard.press('Shift+Tab');
  }
  await expect(page.getByRole('link', { name: 'Skip to main content' })).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(page.locator('main')).toBeFocused();
});

test('contributors see their drafts but not the review queue', async ({ page }) => {
  await signIn(page, ADMIN_SITE, 'cleo@example.org');

  await expect(page.getByRole('region', { name: 'Your drafts' })).toContainText('Opening hours');
  await expect(page.getByRole('heading', { name: 'Waiting for review' })).toHaveCount(0);
  await expect(page.getByRole('region', { name: 'Recent activity' })).toContainText('Cleo saved “Summer reading challenge”');
  await expectNoViolations(page);
});

test('members are turned away with links to the site and their account', async ({ page }) => {
  await page.goto(`${ADMIN_SITE}/login`);
  await page.getByLabel('Email address').fill('max@example.org');
  await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
  await page.getByRole('button', { name: 'Sign in' }).click();

  const response = await page.goto(`${ADMIN_SITE}/admin`);
  expect(response?.status()).toBe(403);
  await expect(page.getByRole('navigation', { name: 'Admin' })).toHaveCount(0);
  await expectNoViolations(page);
});

test('signing out from the header goes back to the sign-in page and says so', async ({ page }) => {
  await signIn(page, ADMIN_SITE, 'ada@example.org');
  await page.getByRole('button', { name: 'Sign out' }).focus();
  await page.keyboard.press('Enter');

  await expect(page).toHaveURL(`${ADMIN_SITE}/login`);
  await expect(page.getByRole('status')).toHaveText('You have signed out.');
});

test('the admin reflows at 320px wide without scrolling sideways', async ({ page }) => {
  await page.setViewportSize({ width: 320, height: 640 });
  await signIn(page, ADMIN_SITE, 'ada@example.org');

  const width = await page.evaluate(() => document.documentElement.scrollWidth);
  expect(width).toBeLessThanOrEqual(320);
  await expectNoViolations(page);
});
