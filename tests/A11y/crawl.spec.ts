// The demo site, crawled (C-8.1, Q-1): a site made with `site:create --with-demo-content` (tests/A11y/demo), in
// production mode, written in British English, European Portuguese and Hebrew (right to left), with sign-up open,
// search and analytics on and the assistant off. Every public page in the per-language sitemaps has no axe
// violations (WCAG 2.2 AA, light and dark mode) and is ready for search engines and AI readers: one <title>, a
// description, an absolute canonical address, valid JSON-LD, a listing in llms.txt and a Markdown version; the
// sitemaps and the `hreflang` sets are reciprocal; the members-only page, the page in the trash and the redirect never
// reach a sitemap; the page with every kind of block also passes in forced colours and reflows at 320px. The public
// screens that are not pages (search, sign-up, My account) pass and are never indexable. Then every admin screen each
// role can reach, found by following the admin's own links, has no axe violations and is never indexable; members
// never reach the admin. A members-only copy of the site (port 8073) shows nothing to anyone who isn't signed in.
import { expect, test, type APIRequestContext, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { expectNoViolations } from './support/admin-site';

const SITE = 'http://127.0.0.1:8087';
const MEMBERS_SITE = 'http://127.0.0.1:8073';
const LOCALES = ['en-GB', 'pt-PT', 'he'];
const PASSWORD = 'Correct horse battery staple 7!';

test.describe.configure({ mode: 'serial' });

test.beforeAll(async ({ request }) => {
  // Making the site in three languages takes a while.
  test.setTimeout(180_000);
  await request.get(`${SITE}/_test/reset`);
  expect((await request.get(`${SITE}/`, { timeout: 170_000 })).status()).toBe(200);
});

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

/** The sitemap index of a site in several languages, followed to every page and its alternates. */
async function sitemapEntries(request: APIRequestContext): Promise<Map<string, Map<string, string>>> {
  const index = await (await request.get(`${SITE}/sitemap.xml`)).text();
  const sitemaps = [...index.matchAll(/<loc>([^<]+)<\/loc>/g)].map((match) => match[1]);
  expect(sitemaps).toEqual(LOCALES.map((locale) => `${SITE}/sitemap-${locale.toLowerCase()}.xml`));
  const all = new Map<string, Map<string, string>>();
  for (const sitemap of sitemaps) {
    for (const [loc, alternates] of entries(await (await request.get(sitemap)).text())) {
      all.set(loc, alternates);
    }
  }

  return all;
}

async function signIn(page: Page, email: string, site: string = SITE): Promise<void> {
  await page.goto(`${site}/login`);
  await page.getByLabel('Email address').fill(email);
  await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await page.waitForLoadState('load');
}

test('every public page is accessible and ready for search engines and AI readers', async ({ page, request }) => {
  test.setTimeout(600_000);
  const urls = [...(await sitemapEntries(request)).keys()];
  const llms = await (await request.get(`${SITE}/llms.txt`)).text();
  for (const path of ['/every-block', '/every-block/under', '/pt-pt/every-block', '/he/every-block/under', '/pt-pt/sobre']) {
    expect(urls).toContain(`${SITE}${path}`);
  }
  expect(urls.length).toBeGreaterThanOrEqual(24);

  for (const url of urls) {
    const response = await page.goto(url);
    expect(response?.status(), url).toBe(200);
    expect(response?.headers()['x-robots-tag'] ?? '', url).not.toContain('noindex');
    await expectNoViolations(page);

    await expect(page.locator('title'), url).toHaveCount(1);
    expect((await page.title()).trim(), url).not.toBe('');
    await expect(page.locator('h1'), url).toHaveCount(1);
    const description = await page.locator('meta[name="description"]').getAttribute('content');
    expect(description?.trim() ?? '', `${url} has a description`).not.toBe('');
    expect(await page.locator('link[rel="canonical"]').getAttribute('href'), url).toBe(url);
    for (const script of await page.locator('script[type="application/ld+json"]').allTextContents()) {
      expect(() => JSON.parse(script), `${url} has valid JSON-LD`).not.toThrow();
    }
    const lang = await page.locator('html').getAttribute('lang');
    expect(LOCALES, `${url} has a language of the site`).toContain(lang);
    await expect(page.locator('html'), url).toHaveAttribute('dir', lang === 'he' ? 'rtl' : 'ltr');
    const markdown = await page.locator('head link[rel="alternate"][type="text/markdown"]:not([hreflang])').getAttribute('href');
    expect(markdown, `${url} has a Markdown version`).not.toBeNull();
    expect(llms, `${url} is in llms.txt`).toContain(`(${markdown})`);
    expect((await request.get(markdown as string)).status(), `${markdown}`).toBe(200);
  }
});

test('the sitemaps and the hreflang sets are reciprocal, and only real, public pages are in them', async ({ page, request }) => {
  test.setTimeout(300_000);
  const all = await sitemapEntries(request);
  expect(Object.fromEntries(all.get(`${SITE}/every-block`) ?? [])).toEqual({
    'en-GB': `${SITE}/every-block`, 'pt-PT': `${SITE}/pt-pt/every-block`, he: `${SITE}/he/every-block`, 'x-default': `${SITE}/every-block`,
  });
  for (const [loc, alternates] of all) {
    for (const [lang, href] of alternates) {
      if (lang !== 'x-default') {
        expect(Object.fromEntries(all.get(href) ?? []), `${href} points back to ${loc}`).toEqual(Object.fromEntries(alternates));
      }
    }
    await page.goto(loc);
    const head = await page.locator('head link[rel="alternate"][hreflang]:not([type])').evaluateAll((links) => links.map((link) => [link.getAttribute('hreflang'), (link as HTMLLinkElement).href]));
    expect(Object.fromEntries(head), `${loc}: the head has the sitemap's set`).toEqual(Object.fromEntries(alternates));
  }

  const llms = await (await request.get(`${SITE}/llms.txt`)).text();
  expect(llms).toContain('## Pages in European Portuguese (pt-PT)');
  expect(llms).toContain('## Pages in Hebrew (he)');
  // The members-only page, the page in the trash and the redirect are in neither.
  for (const path of ['/for-members', '/pt-pt/for-members', '/old-news', '/visit']) {
    expect(all.has(`${SITE}${path}`), path).toBe(false);
    expect(llms, path).not.toContain(`${SITE}${path}`);
  }
  const members = await request.get(`${SITE}/for-members`, { maxRedirects: 0 });
  expect(members.status()).toBe(302);
  expect(new URL(members.headers()['location'], SITE).href).toBe(`${SITE}/login?return=%2Ffor-members`);
  expect((await request.get(`${SITE}/old-news`)).status()).toBe(410);
  const moved = await request.get(`${SITE}/visit`, { maxRedirects: 0 });
  expect(moved.status()).toBe(301);
  expect(new URL(moved.headers()['location'], SITE).href).toBe(`${SITE}/every-block`);
  expect((await request.get(`${SITE}/fr/every-block`)).status()).toBe(404);
});

test('the public screens that are not pages are accessible and never indexable', async ({ page, request }) => {
  test.setTimeout(240_000);
  for (const path of ['/search?q=block', '/search?q=nothing-matches-this', '/search', '/pt-pt/search?q=block', '/he/search?q=block', '/register', '/login', '/login/forgot']) {
    const response = await page.goto(`${SITE}${path}`);
    expect(response?.status(), path).toBe(200);
    expect(response?.headers()['x-robots-tag'] ?? '', path).toContain('noindex');
    await expectNoViolations(page);
  }
  await expect(page.locator('html')).toHaveAttribute('lang', 'en-GB');
  // A search names what was searched for and how much was found.
  await page.goto(`${SITE}/search?q=block`);
  await expect(page.getByRole('heading', { level: 1 })).toContainText('block');
  await expect(page.getByRole('search', { name: 'Search this site' })).toBeVisible();

  await signIn(page, 'max@example.org');
  for (const path of ['/account', '/account/password', '/account/email']) {
    const response = await page.goto(`${SITE}${path}`);
    expect(response?.status(), path).toBe(200);
    expect(response?.headers()['x-robots-tag'] ?? '', path).toContain('noindex');
    await expectNoViolations(page);
  }
  // The member reads the page for members, which still asks search engines to stay away.
  const members = await page.goto(`${SITE}/for-members`);
  expect(members?.status()).toBe(200);
  expect(members?.headers()['x-robots-tag'] ?? '').toContain('noindex');
  await expect(page.getByRole('navigation', { name: 'Main' }).getByRole('link', { name: 'For members' })).toBeVisible();
  await expectNoViolations(page);

  const sitemap = await (await request.get(`${SITE}/sitemap-en-gb.xml`)).text();
  for (const path of ['/search', '/register', '/account']) {
    expect(sitemap, path).not.toContain(`${SITE}${path}`);
  }
});

test('a members-only site shows nothing to anyone who is not signed in', async ({ page, request }) => {
  test.setTimeout(180_000);
  await request.get(`${MEMBERS_SITE}/_test/reset`);
  const robots = await request.get(`${MEMBERS_SITE}/robots.txt`, { timeout: 120_000 });
  expect(robots.status()).toBe(200);
  expect(await robots.text()).toContain('Disallow: /\n');
  for (const path of ['/', '/every-block', '/every-block/under', '/about', '/sitemap.xml', '/llms.txt', '/every-block.md', '/index.md', '/search?q=block']) {
    const response = await request.get(`${MEMBERS_SITE}${path}`, { maxRedirects: 0 });
    expect(response.status(), path).toBe(302);
    expect(new URL(response.headers()['location'] ?? '', MEMBERS_SITE).href, path).toMatch(new RegExp(`^${MEMBERS_SITE}/login(\\?return=|$)`));
  }
  const login = await page.goto(`${MEMBERS_SITE}/every-block`);
  expect(login?.headers()['x-robots-tag'] ?? '').toContain('noindex');
  await expect(page.getByText('This site is for its members. Sign in to read it.')).toBeVisible();
  await expectNoViolations(page);

  await signIn(page, 'max@example.org', MEMBERS_SITE);
  const response = await page.goto(`${MEMBERS_SITE}/every-block`);
  expect(response?.status()).toBe(200);
  expect(response?.headers()['x-robots-tag'] ?? '').toContain('noindex');
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Every kind of block');
});

test('the page with every kind of block works in forced colours and reflows at 320px', async ({ page }) => {
  await page.emulateMedia({ forcedColors: 'active' });
  await page.goto(`${SITE}/every-block`);
  const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
  expect(results.violations, 'forced colours').toEqual([]);

  await page.emulateMedia({ forcedColors: 'none' });
  await page.setViewportSize({ width: 320, height: 640 });
  await page.reload();
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow, 'no sideways scrolling at 320px').toBeLessThanOrEqual(0);
  await expectNoViolations(page);
});

test('nothing private can be indexed', async ({ request }) => {
  const sitemap = [...(await sitemapEntries(request)).keys()].join('\n');
  const llms = await (await request.get(`${SITE}/llms.txt`)).text();
  for (const text of [sitemap, llms]) {
    for (const path of ['/admin', '/login', '/account', '/logout']) {
      expect(text).not.toContain(`${SITE}${path}`);
    }
  }
  const robots = await (await request.get(`${SITE}/robots.txt`)).text();
  expect(robots).toContain('Disallow: /admin');
  for (const path of ['/admin', '/login', '/account']) {
    // Signed out, these send people to sign in; wherever they end up says noindex.
    const response = await request.get(`${SITE}${path}`);
    expect(response.headers()['x-robots-tag'] ?? '', path).toContain('noindex');
  }
});

// Every kind of admin screen each role reaches by following links; a screen that drops out of the crawl fails here.
const SCREENS: Record<'admin' | 'editor' | 'contributor', string[]> = {
  admin: [
    '/account', '/admin', '/admin/analytics', '/admin/analytics/delete', '/admin/appearance', '/admin/appearance/colours', '/admin/audit', '/admin/features', '/admin/media', '/admin/media/{id}', '/admin/media/{id}/delete', '/admin/menus', '/admin/menus/{id}',
    '/admin/pages', '/admin/pages/new', '/admin/pages/{id}', '/admin/pages/{id}/revisions', '/admin/pages/{id}/revisions/{id}', '/admin/redirects', '/admin/settings', '/admin/settings/branding', '/admin/settings/languages', '/admin/settings/sign-up',
    '/admin/users', '/admin/users/invite', '/admin/users/{id}',
  ],
  editor: [
    '/account', '/admin', '/admin/analytics', '/admin/media', '/admin/media/{id}', '/admin/media/{id}/delete', '/admin/menus', '/admin/menus/{id}', '/admin/pages',
    '/admin/pages/new', '/admin/pages/{id}', '/admin/pages/{id}/revisions', '/admin/pages/{id}/revisions/{id}', '/admin/redirects', '/admin/users/invite',
  ],
  contributor: ['/account', '/admin', '/admin/media', '/admin/media/{id}', '/admin/pages', '/admin/pages/new', '/admin/pages/{id}', '/admin/pages/{id}/revisions', '/admin/pages/{id}/revisions/{id}'],
};

for (const [role, email] of [['admin', 'ada@example.org'], ['editor', 'bea@example.org'], ['contributor', 'cleo@example.org']] as const) {
  test(`every admin screen reachable as ${role} is accessible and never indexable`, async ({ page }) => {
    test.setTimeout(300_000);
    await signIn(page, email);
    const seen = new Set<string>();
    const queue = ['/admin', '/account'];
    const visited: string[] = [];
    while (queue.length > 0 && visited.length < 40) {
      const path = queue.shift() as string;
      // One screen of each kind: /admin/pages/12 and /admin/pages/13 are the same screen.
      const kind = path.replace(/\/\d+/g, '/{id}');
      if (seen.has(kind)) {
        continue;
      }
      seen.add(kind);
      const response = await page.goto(`${SITE}${path}`);
      expect(response?.status(), path).toBe(200);
      expect(response?.headers()['x-robots-tag'] ?? '', path).toContain('noindex');
      await expectNoViolations(page);
      visited.push(path);
      for (const href of await page.locator('main a[href^="/admin"], nav[aria-label="Admin"] a[href^="/admin"]').evaluateAll(
        (links) => links.map((link) => link.getAttribute('href') ?? ''),
      )) {
        const target = href.split('#')[0];
        if (target !== '' && !target.endsWith('.csv') && !target.includes('?')) {
          queue.push(target);
        }
      }
    }
    expect([...seen].sort(), `screens reached as ${role}`).toEqual([...SCREENS[role]].sort());
  });
}

test('a member never reaches the admin panel and is told where to go', async ({ page }) => {
  await signIn(page, 'max@example.org');
  await expect(page).toHaveURL(`${SITE}/`);

  const response = await page.goto(`${SITE}/admin`);
  expect(response?.status()).toBe(403);
  await expect(page.getByRole('link', { name: 'My account' }).first()).toBeVisible();
  await expectNoViolations(page);

  await page.goto(`${SITE}/account`);
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
  await expectNoViolations(page);
});
