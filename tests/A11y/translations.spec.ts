// Translations and locale routing (I-2) on a copy of the starter site also written in European Portuguese and Hebrew
// (tests/A11y/starter/index.php with SIGHTPRESS_A11Y_SITE=translated): each page carries its own `lang` and `dir`
// (Hebrew is right to left) with no axe violations, translated pages score 100 for search engines in Lighthouse, the
// sitemaps and the `hreflang` sets are reciprocal, and a page missing in a language is a temporary redirect that no
// sitemap or llms.txt lists.
import { chromium, expect, test, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import lighthouse from 'lighthouse';

const SITE = 'http://127.0.0.1:8084';
const WCAG_22_AA = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

async function expectNoViolations(page: Page): Promise<void> {
  for (const colorScheme of ['light', 'dark'] as const) {
    await page.emulateMedia({ colorScheme });
    const results = await new AxeBuilder({ page }).withTags(WCAG_22_AA).analyze();
    expect(results.violations, `${page.url()} in ${colorScheme} mode`).toEqual([]);
  }
}

/** Every <loc> of a sitemap with its alternates (hreflang => href). */
function entries(xml: string): Map<string, Map<string, string>> {
  const found = new Map<string, Map<string, string>>();
  for (const url of xml.split('<url>').slice(1)) {
    const loc = /<loc>([^<]+)<\/loc>/.exec(url)?.[1] ?? '';
    const alternates = new Map<string, string>();
    for (const link of url.matchAll(/hreflang="([^"]+)" href="([^"]+)"/g)) {
      alternates.set(link[1], link[2]);
    }
    found.set(loc, alternates);
  }

  return found;
}

test.beforeAll(async ({ request }) => {
  await request.get(`${SITE}/_test/reset`);
});

test('each translation has its own language and direction, and no violations', async ({ page }) => {
  for (const [path, lang, dir, title] of [
    ['/pt-pt', 'pt-PT', 'ltr', 'Bem-vindo à Riverside Library'],
    ['/pt-pt/sobre', 'pt-PT', 'ltr', 'Sobre nós'],
    ['/he/odot', 'he', 'rtl', 'אודותינו'],
  ] as const) {
    await page.setViewportSize({ width: 1280, height: 800 });
    expect((await page.goto(`${SITE}${path}`))?.status()).toBe(200);
    await expect(page.locator('html')).toHaveAttribute('lang', lang);
    await expect(page.locator('html')).toHaveAttribute('dir', dir);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(title);
    await expect(page.locator('meta[property="og:locale"]')).toHaveAttribute('content', lang.replace('-', '_'));
    await expectNoViolations(page);
    await page.setViewportSize({ width: 320, height: 640 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth), `${path} reflows`).toBeLessThanOrEqual(320);
  }
});

test('the sitemaps and the hreflang sets are reciprocal, and fallbacks are in neither', async ({ page, request }) => {
  const index = await (await request.get(`${SITE}/sitemap.xml`)).text();
  const sitemaps = [...index.matchAll(/<loc>([^<]+)<\/loc>/g)].map((match) => match[1]);
  expect(sitemaps).toEqual([`${SITE}/sitemap-en-gb.xml`, `${SITE}/sitemap-pt-pt.xml`, `${SITE}/sitemap-he.xml`]);
  const all = new Map<string, Map<string, string>>();
  for (const sitemap of sitemaps) {
    for (const [loc, alternates] of entries(await (await request.get(sitemap)).text())) {
      all.set(loc, alternates);
    }
  }
  expect(all.has(`${SITE}/he`)).toBe(false);
  const about = all.get(`${SITE}/about`);
  expect(Object.fromEntries(about ?? [])).toEqual({ 'en-GB': `${SITE}/about`, 'pt-PT': `${SITE}/pt-pt/sobre`, he: `${SITE}/he/odot`, 'x-default': `${SITE}/about` });
  for (const [loc, alternates] of all) {
    for (const [lang, href] of alternates) {
      if (lang !== 'x-default') {
        expect(Object.fromEntries(all.get(href) ?? []), `${href} points back to ${loc}`).toEqual(Object.fromEntries(alternates));
      }
    }
    await page.goto(loc);
    const head = await page.locator('head link[rel="alternate"][hreflang]:not([type])').evaluateAll((links) => links.map((link) => [link.getAttribute('hreflang'), (link as HTMLLinkElement).href]));
    expect(Object.fromEntries(head), `${loc}: the head has the sitemap's set`).toEqual(Object.fromEntries(alternates));
    await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', loc);
  }

  const fallback = await request.get(`${SITE}/he`, { maxRedirects: 0 });
  expect(fallback.status()).toBe(302);
  expect(fallback.headers()['location']).toBe(`${SITE}/`);
  expect(fallback.headers()['content-language']).toBe('en-GB');
  const llms = await (await request.get(`${SITE}/llms.txt`)).text();
  expect(llms).toContain('## Pages in Hebrew (he)');
  expect(llms).toContain(`(${SITE}/he/odot.md)`);
  expect(llms).not.toContain(`(${SITE}/he.md)`);
  expect((await request.get(`${SITE}/fr/about`)).status()).toBe(404);
});

test('the home page is in the visitor’s language only at the top address', async ({ browser }) => {
  const context = await browser.newContext({ locale: 'pt-BR' });
  const page = await context.newPage();
  await page.goto(`${SITE}/`);
  await expect(page).toHaveURL(`${SITE}/pt-pt`);
  await expect(page.locator('html')).toHaveAttribute('lang', 'pt-PT');
  await page.goto(`${SITE}/about`);
  await expect(page).toHaveURL(`${SITE}/about`);
  await context.close();
});

test('translated pages score 100 for search engines in Lighthouse', async () => {
  test.setTimeout(180_000);
  const port = 9400 + test.info().workerIndex;
  const browser = await chromium.launch({ args: [`--remote-debugging-port=${port}`] });
  try {
    for (const path of ['/pt-pt', '/pt-pt/sobre', '/he/odot']) {
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
