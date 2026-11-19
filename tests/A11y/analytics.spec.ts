// The Analytics screen and the "Last 7 days" dashboard widget (AN-3) on the admin test site, with 60 days of figures:
// an admin changes the range by keyboard (preset links with aria-current, then the custom range form with its error
// summary) and downloads a CSV; an editor sees the reports but not "Keeping analytics"; a contributor has no menu
// entry and gets 403; retention and "Delete analytics data" work through plain forms. No axe violations in light and
// dark mode, in forced colours or at 320 px; everything works without JavaScript.
import { expect, test } from '@playwright/test';
import { ADMIN_SITES, expectNoViolations, signIn } from './support/admin-site';

const SITE = ADMIN_SITES.analytics;

test.beforeEach(async ({ request }) => {
  await request.get(`${SITE}/_test/reset`);
  expect((await request.get(`${SITE}/_test/analytics`)).status()).toBe(204);
});

test('an admin changes the range by keyboard and downloads a CSV', async ({ page }) => {
  await signIn(page, SITE, 'ada@example.org', '/admin/analytics');
  await expect(page).toHaveTitle('Analytics: Last 30 days - Admin - Riverside Library');
  await expect(page.getByRole('navigation', { name: 'Admin' }).getByRole('link', { name: 'Analytics' })).toHaveAttribute('aria-current', 'page');
  const ranges = page.getByRole('navigation', { name: 'Days to show' });
  await expect(ranges.getByRole('link', { name: 'Last 30 days' })).toHaveAttribute('aria-current', 'page');
  await expect(page.getByRole('heading', { level: 2, name: /^Last 30 days: / })).toBeVisible();
  for (const name of ['Visitors over time', 'Pages read most', 'Where visitors came from', 'Devices', 'Site search', 'Pages not found', 'Search engines and AI crawlers', 'Keeping analytics']) {
    await expect(page.getByRole('heading', { level: 2, name, exact: true })).toBeVisible();
  }
  await expect(page.getByRole('heading', { level: 2, name: 'Languages' })).toHaveCount(0);
  await expect(page.getByRole('img', { name: /^Line chart of visitors per day, / })).toHaveAccessibleDescription(/^Visitors went (up|down)|^Visitors stayed/);
  await expectNoViolations(page);

  await ranges.getByRole('link', { name: 'Last 7 days' }).focus();
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(`${SITE}/admin/analytics?range=7`);
  await expect(page.getByRole('navigation', { name: 'Days to show' }).getByRole('link', { name: 'Last 7 days' })).toHaveAttribute('aria-current', 'page');

  await page.getByLabel('Last day').focus();
  await page.keyboard.type('2026-01-31');
  await page.getByRole('button', { name: 'Show these days' }).focus();
  await page.keyboard.press('Enter');
  const summary = page.getByRole('region', { name: 'There is a problem' });
  await expect(summary).toBeFocused();
  await expect(summary.getByRole('link', { name: 'Enter the first day as a date, for example 2026-11-01' })).toHaveAttribute('href', '#analytics-range-from');
  await expect(page.getByLabel('First day')).toHaveAttribute('aria-invalid', 'true');
  await expectNoViolations(page);

  await page.getByLabel('First day').fill('2026-01-01');
  await page.getByLabel('Last day').fill('2026-01-31');
  await page.getByRole('button', { name: 'Show these days' }).press('Enter');
  await expect(page.getByRole('heading', { level: 2, name: 'The chosen days: 1 January 2026 to 31 January 2026' })).toBeVisible();
  await expect(page.getByRole('navigation', { name: 'Days to show' }).locator('[aria-current]')).toHaveCount(0);

  await page.getByRole('link', { name: 'Last 30 days' }).click();
  const csv = page.getByRole('link', { name: 'Download the pages not found as a CSV file' });
  await csv.focus();
  const [download] = await Promise.all([page.waitForEvent('download'), page.keyboard.press('Enter')]);
  expect(download.suggestedFilename()).toMatch(/^analytics-demo-not-found-\d{4}-\d\d-\d\d-to-\d{4}-\d\d-\d\d\.csv$/);
  const text = await (await download.createReadStream()).toArray().then((chunks) => Buffer.concat(chunks).toString('utf8'));
  expect(text).toMatch(/^"path \(\d{4}-\d\d-\d\d to \d{4}-\d\d-\d\d, Europe\/London\)",requests\r\n\/old-news,\d+\r\n$/);
});

test('the dashboard shows the last 7 days to editors, who see the reports but not the settings', async ({ page }) => {
  await signIn(page, SITE, 'bea@example.org', '/admin');
  const widget = page.getByRole('region', { name: 'Last 7 days' });
  await expect(widget).toContainText(/visitors and \d+ page views in the last 7 days; visitors /);
  await widget.getByRole('link', { name: 'See all analytics' }).click();
  await expect(page).toHaveURL(`${SITE}/admin/analytics`);
  await expect(page.getByRole('heading', { name: 'Keeping analytics' })).toHaveCount(0);
  await expectNoViolations(page);
});

test('contributors have no analytics', async ({ page }) => {
  await signIn(page, SITE, 'cleo@example.org', '/admin');
  await expect(page.getByRole('navigation', { name: 'Admin' }).getByRole('link', { name: 'Analytics' })).toHaveCount(0);
  await expect(page.getByRole('region', { name: 'Last 7 days' })).toHaveCount(0);
  expect((await page.goto(`${SITE}/admin/analytics`))?.status()).toBe(403);
  expect((await page.goto(`${SITE}/admin/analytics/download?report=pages`))?.status()).toBe(403);
});

test('the screen passes axe in forced colours and at 320 px', async ({ page }) => {
  await signIn(page, SITE, 'ada@example.org', '/admin/analytics');
  await page.emulateMedia({ forcedColors: 'active' });
  await expectNoViolations(page);
  await page.emulateMedia({ forcedColors: 'none' });
  await page.setViewportSize({ width: 320, height: 640 });
  await expectNoViolations(page);
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
  expect(overflow).toBe(false);
});

test('an admin changes how long analytics are kept and deletes them after confirming', async ({ page }) => {
  await signIn(page, SITE, 'ada@example.org', '/admin/analytics');
  await page.getByLabel('Months to keep analytics for').fill('13');
  await page.getByRole('button', { name: 'Save' }).press('Enter');
  await expect(page.getByRole('status')).toHaveText('Analytics are now kept for 13 months.');
  await expect(page.getByLabel('Months to keep analytics for')).toHaveValue('13');

  await page.getByRole('link', { name: 'Delete analytics data' }).click();
  await expect(page).toHaveTitle('Delete all analytics data? - Admin - Riverside Library');
  await expectNoViolations(page);
  await page.getByRole('button', { name: 'Delete analytics data' }).press('Enter');
  await expect(page.getByRole('status')).toHaveText('Deleted all of the site’s analytics. Counting goes on from now.');
  await expect(page.locator('#analytics-pages').locator('..').getByText('Nothing counted in these days.')).toBeVisible();

  await page.goto(`${SITE}/admin/audit`);
  await expect(page.getByRole('cell', { name: 'Deleted all analytics data.' })).toBeVisible();
  await expect(page.getByRole('cell', { name: /Changed the site settings: How long analytics are kept/ })).toBeVisible();
});

test.describe('without JavaScript', () => {
  test.use({ javaScriptEnabled: false });

  test('the range form, the daily data and the CSV work with plain HTML', async ({ page, request }) => {
    await signIn(page, SITE, 'ada@example.org', '/admin/analytics');
    await page.getByLabel('First day').fill('2026-10-01');
    await page.getByLabel('Last day').fill('2026-10-07');
    await page.getByRole('button', { name: 'Show these days' }).click();
    await expect(page).toHaveURL(`${SITE}/admin/analytics?from=2026-10-01&to=2026-10-07`);
    await page.getByText('Show the data for each day').click();
    await expect(page.getByRole('table', { name: 'Visitors for each day' }).getByRole('row')).toHaveCount(8);
    const href = await page.getByRole('link', { name: 'Download visitors and page views per day as a CSV file' }).getAttribute('href');
    expect(href).toBe('/admin/analytics/download?report=daily&from=2026-10-01&to=2026-10-07');
  });
});
