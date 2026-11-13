// The "no site configured" page: what every address shows until a site exists.
import { expect, test } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const WCAG_22_AA = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

for (const path of ['/', '/any/other/address']) {
  test.describe(`no site configured at ${path}`, () => {
    test('answers 404 with a page that has a language, a title, one main landmark and one heading', async ({ page }) => {
      const response = await page.goto(path);

      expect(response?.status()).toBe(404);
      await expect(page.locator('html')).toHaveAttribute('lang', 'en-GB');
      await expect(page).toHaveTitle('No site configured - SightPress');
      await expect(page.getByRole('main')).toHaveCount(1);
      await expect(page.getByRole('heading', { level: 1 })).toHaveText('No site configured');
    });

    test('has no WCAG 2.2 AA violations in light or dark mode', async ({ page }) => {
      for (const colorScheme of ['light', 'dark'] as const) {
        await page.emulateMedia({ colorScheme });
        await page.goto(path);

        const results = await new AxeBuilder({ page }).withTags(WCAG_22_AA).analyze();

        expect(results.violations, `${colorScheme} mode`).toEqual([]);
      }
    });
  });
}
