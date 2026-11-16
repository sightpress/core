// The starter content (C-6.4) of a site made with site:create (tests/A11y/starter/index.php): every starter page has
// no axe violations in light and dark mode, reflows at 320 px, and can be reached from the menus by keyboard.
import { expect, test, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const SITE = 'http://127.0.0.1:8091';
const WCAG_22_AA = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

const PAGES = [
  ['/', 'Welcome to Riverside Library'],
  ['/about', 'About us'],
  ['/contact', 'Contact us'],
  ['/privacy', 'Privacy notice'],
  ['/terms', 'Terms of use'],
  ['/accessibility', 'Accessibility statement'],
] as const;

async function expectNoViolations(page: Page): Promise<void> {
  for (const colorScheme of ['light', 'dark'] as const) {
    await page.emulateMedia({ colorScheme });
    const results = await new AxeBuilder({ page }).withTags(WCAG_22_AA).analyze();
    expect(results.violations, `${page.url()} in ${colorScheme} mode`).toEqual([]);
  }
}

test.beforeAll(async ({ request }) => {
  await request.get(`${SITE}/_test/reset`);
});

test('every starter page has no violations and reflows at 320 px', async ({ page }) => {
  test.setTimeout(60_000);
  for (const [path, title] of PAGES) {
    await page.setViewportSize({ width: 1280, height: 800 });
    expect((await page.goto(`${SITE}${path}`))?.status()).toBe(200);
    await expect(page).toHaveTitle(`${title} - Riverside Library`);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(title);
    await expectNoViolations(page);

    await page.setViewportSize({ width: 320, height: 640 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
  }
});

test('the menus lead to the starter pages by keyboard', async ({ page }) => {
  await page.goto(`${SITE}/`);

  await expect(page.getByRole('navigation', { name: 'Main' }).getByRole('link')).toHaveText(['About us', 'Contact us']);
  await expect(page.getByRole('navigation', { name: 'Footer' }).getByRole('link')).toHaveText(['Privacy notice', 'Terms of use', 'Accessibility statement']);

  await page.getByRole('navigation', { name: 'Footer' }).getByRole('link', { name: 'Accessibility statement' }).press('Enter');
  await expect(page).toHaveURL(`${SITE}/accessibility`);
  await expect(page.getByRole('navigation', { name: 'Breadcrumb' }).getByRole('link')).toHaveText(['Home', 'Accessibility statement']);
  await page.getByRole('main').getByRole('link', { name: 'contact us' }).press('Enter');
  await expect(page).toHaveURL(`${SITE}/contact`);
  await expect(page.getByLabel('Your message')).toBeVisible();
});
