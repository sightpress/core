// Accessible charts (AN-2) on the component gallery's chart style guide (/charts): no axe violations in light and
// dark mode (with and without more contrast) and in forced colours; every chart is an image with a name and its
// summary as description, its data is in a table, the daily table opens by keyboard, an empty range is a sentence,
// and the page reflows at 320 px with the chart text at the page's own size.
import { expect, test, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const GALLERY = process.env.SIGHTPRESS_GALLERY_URL ?? 'http://127.0.0.1:8099';
const WCAG_22_AA = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

async function expectNoViolations(page: Page, label: string): Promise<void> {
  const results = await new AxeBuilder({ page }).withTags(WCAG_22_AA).analyze();
  expect(results.violations, label).toEqual([]);
}

test('the charts have no violations in any colour mode or in forced colours', async ({ page }) => {
  await page.goto(`${GALLERY}/charts`);
  for (const colorScheme of ['light', 'dark'] as const) {
    for (const contrast of ['no-preference', 'more'] as const) {
      await page.emulateMedia({ colorScheme, contrast });
      await expectNoViolations(page, `${colorScheme} mode, ${contrast} contrast`);
    }
  }
  await page.emulateMedia({ colorScheme: 'light', contrast: 'no-preference', forcedColors: 'active' });
  await expectNoViolations(page, 'forced colours');
  const line = page.locator('.chart__series--current line').first();
  expect(await line.evaluate((element) => getComputedStyle(element).stroke)).not.toBe('none');
});

test('every chart is an image named by its label and described by its summary, with its data in a table', async ({ page }) => {
  await page.goto(`${GALLERY}/charts`);

  const charts = page.locator('svg[role="img"]');
  await expect(charts).toHaveCount(4);
  for (const chart of await charts.all()) {
    await expect(chart).toHaveAttribute('aria-label', /chart/);
    const summary = page.locator(`#${await chart.getAttribute('aria-describedby')}`);
    await expect(summary).toHaveCount(1);
    expect(await summary.evaluate((element) => element.tagName)).toBe('FIGCAPTION');
  }
  await expect(page.getByRole('img', { name: 'Line chart of visitors per day, 15 October 2026 to 13 November 2026' })).toHaveAccessibleDescription(
    'Visitors went up 24.5% compared with the previous 30 days, from 1,283 to 1,597. The busiest day was Sunday 18 October, with 84 visitors. The quietest day was Thursday 15 October, with 30 visitors.',
  );
  await expect(page.getByText('The 30 days before: dashed line with square markers')).toBeVisible();

  const empty = page.locator('section', { has: page.getByRole('heading', { name: 'No days' }) });
  await expect(empty.getByText('There is nothing to show for these dates.')).toBeVisible();
  await expect(empty.locator('svg')).toHaveCount(0);

  const devices = page.getByRole('table', { name: 'Devices' });
  await expect(devices.getByRole('rowheader', { name: 'Phone' })).toBeVisible();
  await expect(devices.getByRole('row', { name: /Phone/ }).getByRole('cell')).toHaveText('498');
  await expect(devices.locator('svg[aria-hidden="true"]')).toHaveCount(3);
});

test('the data for each day opens by keyboard', async ({ page }) => {
  await page.goto(`${GALLERY}/charts`);
  const disclosure = page.locator('summary', { hasText: 'Show the data for each day' }).first();
  const table = page.getByRole('table', { name: 'Visitors for each day' }).first();
  await expect(table).toBeHidden();

  await disclosure.focus();
  await page.keyboard.press('Enter');

  await expect(table).toBeVisible();
  await expect(table.getByRole('columnheader', { name: 'Day', exact: true })).toBeVisible();
  await expect(table.getByRole('row', { name: /Sunday 18 October/ }).getByRole('cell').first()).toHaveText('84');
});

test('the charts reflow at 320 px and their text keeps the page size', async ({ page }) => {
  await page.setViewportSize({ width: 320, height: 800 });
  await page.goto(`${GALLERY}/charts`);

  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(0);
  const size = await page.locator('.chart__svg--line text').first().evaluate((element) => parseFloat(getComputedStyle(element).fontSize));
  expect(size).toBeGreaterThanOrEqual(14);
  await expectNoViolations(page, '320 px');
});
