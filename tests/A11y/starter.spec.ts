// The starter content (C-6.4) of a site made with site:create (tests/A11y/starter/index.php): every starter page has
// no axe violations in light and dark mode, reflows at 320 px, can be reached from the menus by keyboard, and scores
// 100 in Lighthouse's SEO category (C-6.7), and is offered to AI readers as Markdown and in llms.txt (C-6.8).
import { chromium, expect, test, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import lighthouse from 'lighthouse';

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
    // The home page's title already names the site, so it isn't repeated (C-6.7).
    await expect(page).toHaveTitle(path === '/' ? title : `${title} - Riverside Library`);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(title);
    await expectNoViolations(page);

    await page.setViewportSize({ width: 320, height: 640 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
  }
});

test('the icons, manifest and share image in the head all load (C-6.6)', async ({ page, request }) => {
  await page.goto(`${SITE}/about`);

  const hrefs = await page.locator('head link[rel~="icon"], head link[rel="apple-touch-icon"]').evaluateAll((links) => links.map((link) => (link as HTMLLinkElement).href));
  expect(hrefs.length).toBe(3);
  for (const href of hrefs) {
    expect((await request.get(href)).status(), href).toBe(200);
  }
  const manifest = await (await request.get(`${SITE}/site.webmanifest`)).json();
  expect(manifest.name).toBe('Riverside Library');
  for (const icon of manifest.icons) {
    expect((await request.get(`${SITE}${icon.src}`)).headers()['content-type']).toBe(icon.type);
  }
  const image = await page.locator('meta[property="og:image"]').getAttribute('content');
  expect(image).toMatch(/^http:\/\/127\.0\.0\.1:8091\/branding\/share\.png\?v=[0-9a-f]{12}$/);
  expect((await request.get(image ?? '')).headers()['cache-control']).toBe('public, max-age=31536000, immutable');
  const svg = await request.get(`${SITE}/icon.svg`);
  expect(svg.headers()['content-security-policy']).toBe("default-src 'none'; style-src 'unsafe-inline'; sandbox");
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

test('AI readers find every starter page as Markdown and in llms.txt (C-6.8)', async ({ page, request }) => {
  await page.goto(`${SITE}/privacy`);
  const alternate = await page.locator('head link[rel="alternate"][type="text/markdown"]').getAttribute('href');
  expect(alternate).toBe(`${SITE}/privacy.md`);

  const markdown = await request.get(alternate ?? '');
  expect(markdown.headers()['content-type']).toBe('text/markdown; charset=utf-8');
  expect(markdown.headers()['vary']).toBe('Accept');
  expect(await markdown.text()).toMatch(/^# Privacy notice\n\nThis notice explains/);

  const llms = await (await request.get(`${SITE}/llms.txt`)).text();
  expect(llms).toMatch(/^# Riverside Library\n\n> /);
  for (const [path, title] of PAGES) {
    expect(llms).toContain(`- [${title}](${SITE}${path === '/' ? '/index' : path}.md)`);
  }
  expect(await (await request.get(`${SITE}/llms-full.txt`)).text()).toContain('# Accessibility statement');
});

test('every starter page scores 100 for search engines in Lighthouse', async () => {
  test.setTimeout(240_000);
  const port = 9300 + test.info().workerIndex;
  const browser = await chromium.launch({ args: [`--remote-debugging-port=${port}`] });
  try {
    for (const [path] of PAGES) {
      const result = await lighthouse(`${SITE}${path}`, { port, onlyCategories: ['seo'], logLevel: 'error', output: 'json' });
      const lhr = result?.lhr;
      const failed = Object.values(lhr?.audits ?? {})
        .filter((audit) => audit.scoreDisplayMode === 'binary' && audit.score !== 1)
        .map((audit) => `${audit.id}: ${audit.title}`);
      expect(lhr?.categories.seo.score, `${path}: ${failed.join('; ')}`).toBe(1);
    }
  } finally {
    await browser.close();
  }
});
