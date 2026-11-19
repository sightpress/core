// Site search (SR-2) on a copy of the demo site (tests/A11y/demo/index.php, port 8074): the results, no-results,
// empty-query and too-many-searches pages have no axe violations in light and dark mode and in forced colours, and
// reflow at 320 px; someone searches from the header by keyboard and reaches the first result; the page title says
// what was searched for and how many results there are; queries are only ever shown as text; search engines are
// asked to leave the results out; and the form works without JavaScript.
import { expect, test, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const SITE = 'http://127.0.0.1:8074';
const WCAG_22_AA = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

async function expectNoViolations(page: Page): Promise<void> {
  for (const media of [{ colorScheme: 'light' }, { colorScheme: 'dark' }, { colorScheme: 'light', forcedColors: 'active' }] as const) {
    await page.emulateMedia(media);
    const results = await new AxeBuilder({ page }).withTags(WCAG_22_AA).analyze();
    expect(results.violations, `${page.url()} with ${JSON.stringify(media)}`).toEqual([]);
  }
  await page.emulateMedia({ colorScheme: 'light', forcedColors: 'none' });
  await page.setViewportSize({ width: 320, height: 640 });
  expect(await page.evaluate(() => document.documentElement.scrollWidth), `${page.url()} at 320 px`).toBeLessThanOrEqual(320);
  await page.setViewportSize({ width: 1280, height: 800 });
}

test.describe.configure({ mode: 'serial' });

test.beforeAll(async ({ request }) => {
  await request.get(`${SITE}/_test/reset`);
  await request.get(`${SITE}/`);
});

test('the results, no-results and empty search pages have no violations and say what they show', async ({ page }) => {
  test.setTimeout(90_000);
  const screens = [
    ['/search?q=block', /^\d+ results? for “block” - Riverside Library$/, /^\d+ results? for “block”$/],
    ['/search?q=zzzzzz', 'No results for “zzzzzz” - Riverside Library', 'No results for “zzzzzz”'],
    ['/search', 'Search - Riverside Library', 'Search'],
  ] as const;
  for (const [path, title, heading] of screens) {
    const response = await page.goto(`${SITE}${path}`);
    expect(response?.status()).toBe(200);
    expect(response?.headers()['x-robots-tag']).toBe('noindex, follow');
    await expect(page).toHaveTitle(title);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(heading);
    await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', 'noindex, follow');
    await expect(page.locator('link[rel="canonical"]')).toHaveCount(0);
    await expectNoViolations(page);
  }
  await page.goto(`${SITE}/search?q=block`);
  await expect(page.getByRole('search', { name: 'Search this site' })).toBeVisible();
  await expect(page.getByRole('search', { name: 'Search for' })).toBeVisible();
  await expect(page.locator('.search-result mark').first()).toBeVisible();
});

test('someone searches from the header by keyboard and reaches the first result', async ({ page }) => {
  await page.goto(`${SITE}/`);
  const input = page.getByRole('searchbox', { name: 'Search this site' });
  let tabs = 0;
  while (!(await input.evaluate((element) => element === document.activeElement)) && tabs < 30) {
    await page.keyboard.press('Tab');
    tabs++;
  }
  await expect(input).toBeFocused();
  await page.keyboard.type('every block');
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(`${SITE}/search?q=every+block`);
  await expect(page).toHaveTitle(/^\d+ results? for “every block” - Riverside Library$/);

  const first = page.locator('.search-result__title a').first();
  tabs = 0;
  while (!(await first.evaluate((element) => element === document.activeElement)) && tabs < 40) {
    await page.keyboard.press('Tab');
    tabs++;
  }
  await expect(first).toBeFocused();
  await expect(first).toHaveText('Every kind of block');
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(`${SITE}/every-block`);
});

test('queries are shown as text and the results page is never listed', async ({ page, request }) => {
  let dialogs = 0;
  page.on('dialog', async (dialog) => {
    dialogs++;
    await dialog.dismiss();
  });
  for (const text of ['<script>alert(1)</script>', '"><img src=x onerror=alert(1)>']) {
    await page.goto(`${SITE}/search?q=${encodeURIComponent(text)}`);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(`No results for “${text}”`);
    await expect(page.getByRole('searchbox', { name: 'Search for' })).toHaveValue(text);
  }
  expect(dialogs).toBe(0);
  expect(await page.locator('main img').count()).toBe(0);

  for (const file of ['/sitemap.xml', '/llms.txt']) {
    expect(await (await request.get(`${SITE}${file}`)).text(), file).not.toContain('/search');
  }
});

test.describe('without JavaScript', () => {
  test.use({ javaScriptEnabled: false });

  test('the header form searches', async ({ page }) => {
    await page.goto(`${SITE}/about`);
    await page.getByRole('searchbox', { name: 'Search this site' }).fill('accessibility');
    await page.getByRole('searchbox', { name: 'Search this site' }).press('Enter');
    await expect(page).toHaveURL(`${SITE}/search?q=accessibility`);
    await expect(page.getByRole('main').getByRole('link', { name: 'Accessibility statement' })).toBeVisible();
  });
});

// Last: it uses up the rate limit of this copy for a minute.
test('too many searches give a page that says when to try again', async ({ page, request }) => {
  test.setTimeout(90_000);
  let status = 200;
  for (let search = 0; search < 40 && status !== 429; search++) {
    status = (await request.get(`${SITE}/search?q=library`)).status();
  }
  expect(status).toBe(429);
  const response = await page.goto(`${SITE}/search?q=library`);
  expect(response?.status()).toBe(429);
  expect(Number(response?.headers()['retry-after'])).toBeGreaterThan(0);
  await expect(page).toHaveTitle('Too many searches - Riverside Library');
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Too many searches');
  await expect(page.getByText(/Wait \d+ minutes?, then search again\./)).toBeVisible();
  await expectNoViolations(page);
});
