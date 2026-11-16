// The admin panel (C-7.1 and later) on a real site (tests/A11y/admin/index.php): no axe violations in light and dark
// mode, everything works by keyboard, the navigation shows only what each role may use, and nothing is wider than
// the screen at 320px.
import { expect, test, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

export const ADMIN_SITE = 'http://127.0.0.1:8096';
const PASSWORD = 'correct horse battery staple';
const WCAG_22_AA = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

export async function expectNoViolations(page: Page): Promise<void> {
  for (const colorScheme of ['light', 'dark'] as const) {
    await page.emulateMedia({ colorScheme });
    const results = await new AxeBuilder({ page }).withTags(WCAG_22_AA).analyze();
    expect(results.violations, `${colorScheme} mode`).toEqual([]);
  }
}

export async function signIn(page: Page, email: string, path = '/admin'): Promise<void> {
  await page.goto(`${ADMIN_SITE}/login?return=${encodeURIComponent(path)}`);
  await page.getByLabel('Email address').fill(email);
  await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page).toHaveURL(`${ADMIN_SITE}${path}`);
}

test.beforeEach(async ({ request }) => {
  await request.get(`${ADMIN_SITE}/_test/reset`);
});

test('the dashboard has landmarks, a skip link and the current screen marked', async ({ page }) => {
  await signIn(page, 'bea@example.org');

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
  await expect(page.getByRole('button', { name: 'Sign out' })).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(nav.getByRole('link', { name: 'Dashboard' })).toBeFocused();

  await page.keyboard.press('Shift+Tab');
  await page.keyboard.press('Shift+Tab');
  await page.keyboard.press('Shift+Tab');
  await expect(page.getByRole('link', { name: 'Skip to main content' })).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(page.locator('main')).toBeFocused();
});

test('contributors see their drafts but not the review queue', async ({ page }) => {
  await signIn(page, 'cleo@example.org');

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
  await signIn(page, 'ada@example.org');
  await page.getByRole('button', { name: 'Sign out' }).focus();
  await page.keyboard.press('Enter');

  await expect(page).toHaveURL(`${ADMIN_SITE}/login`);
  await expect(page.getByRole('status')).toHaveText('You have signed out.');
});

test('the admin reflows at 320px wide without scrolling sideways', async ({ page }) => {
  await page.setViewportSize({ width: 320, height: 640 });
  await signIn(page, 'ada@example.org');

  const width = await page.evaluate(() => document.documentElement.scrollWidth);
  expect(width).toBeLessThanOrEqual(320);
  await expectNoViolations(page);
});
