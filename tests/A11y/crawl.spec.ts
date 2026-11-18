// The demo site, crawled (C-8.1): a site made with `site:create --with-demo-content` (tests/A11y/demo), in
// production mode. Every public page in the sitemap has no axe violations (WCAG 2.2 AA, light and dark mode) and is
// ready for search engines and AI readers: one <title>, a description, an absolute canonical address, valid JSON-LD,
// a listing in llms.txt and a Markdown version; the page with every kind of block also passes in forced colours and
// reflows at 320px. Then every admin screen each role can reach, found by following
// the admin's own links, has no axe violations and is never indexable; members never reach the admin.
import { expect, test, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { expectNoViolations } from './support/admin-site';

const SITE = 'http://127.0.0.1:8087';
const PASSWORD = 'Correct horse battery staple 7!';

test.describe.configure({ mode: 'serial' });

test.beforeAll(async ({ request }) => {
  await request.get(`${SITE}/_test/reset`);
  expect((await request.get(`${SITE}/`)).status()).toBe(200);
});

async function signIn(page: Page, email: string): Promise<void> {
  await page.goto(`${SITE}/login`);
  await page.getByLabel('Email address').fill(email);
  await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await page.waitForLoadState('load');
}

test('every public page is accessible and ready for search engines and AI readers', async ({ page, request }) => {
  test.setTimeout(240_000);
  const sitemap = await (await request.get(`${SITE}/sitemap.xml`)).text();
  const urls = [...sitemap.matchAll(/<loc>([^<]+)<\/loc>/g)].map((match) => match[1]);
  const llms = await (await request.get(`${SITE}/llms.txt`)).text();
  expect(urls).toContain(`${SITE}/every-block`);
  expect(urls).toContain(`${SITE}/every-block/under`);
  expect(urls.length).toBeGreaterThanOrEqual(8);

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
    const path = new URL(url).pathname;
    const markdown = path === '/' ? '/index.md' : `${path}.md`;
    expect(llms, `${url} is in llms.txt`).toContain(`${SITE}${markdown}`);
    expect((await request.get(`${SITE}${markdown}`)).status(), `${markdown}`).toBe(200);
  }
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
  const sitemap = await (await request.get(`${SITE}/sitemap.xml`)).text();
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
    '/account', '/admin', '/admin/audit', '/admin/media', '/admin/media/{id}', '/admin/media/{id}/delete', '/admin/menus',
    '/admin/pages', '/admin/pages/new', '/admin/pages/{id}', '/admin/pages/{id}/revisions', '/admin/pages/{id}/revisions/{id}', '/admin/redirects', '/admin/settings', '/admin/settings/branding', '/admin/settings/languages',
    '/admin/users', '/admin/users/invite', '/admin/users/{id}',
  ],
  editor: [
    '/account', '/admin', '/admin/media', '/admin/media/{id}', '/admin/media/{id}/delete', '/admin/menus', '/admin/pages',
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
