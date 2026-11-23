// The M3 crawl (Q-1), on two copies of the demo site (tests/A11y/demo):
// - `themed` uses a second installed theme, which has the sidebar layout but not landing or full-width: every public
//   page, the Appearance and File storage screens and the static preview of every installed theme have no axe
//   violations, and a page in a layout the theme lacks is still served;
// - `cloud` keeps its files in MinIO and delivers them through a CDN address, answered here in the browser from the
//   site itself: every public page has no axe violations, its files come from the CDN and load, and the captioned video
//   and the recording's transcript are on the page.
import { expect, test, type APIRequestContext, type Page } from '@playwright/test';
import { expectNoViolations } from './support/admin-site';

const THEMED = 'http://127.0.0.1:8064';
const CLOUD = 'http://127.0.0.1:8063';
const CDN = 'https://cdn.sightpress.test';
const PASSWORD = 'Correct horse battery staple 7!';

test.describe.configure({ mode: 'serial' });

test.beforeAll(async ({ request }) => {
  test.setTimeout(360_000);
  for (const site of [THEMED, CLOUD]) {
    await request.get(`${site}/_test/reset`);
    expect((await request.get(`${site}/`, { timeout: 170_000 })).status(), site).toBe(200);
  }
});

async function publicPages(request: APIRequestContext, site: string): Promise<string[]> {
  const sitemap = await (await request.get(`${site}/sitemap.xml`)).text();

  return [...sitemap.matchAll(/<loc>([^<]+)<\/loc>/g)].map((match) => match[1]);
}

async function signIn(page: Page, site: string): Promise<void> {
  await page.goto(`${site}/login`);
  await page.getByLabel('Email address').fill('ada@example.org');
  await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await page.waitForLoadState('load');
}

/** Answers the CDN's address from the site, as a pull-through CDN would. */
async function serveCdnFromSite(page: Page): Promise<void> {
  await page.route(`${CDN}/**`, async (route) => {
    const url = new URL(route.request().url());
    const response = await page.request.fetch(`${CLOUD}${url.pathname}${url.search}`, { headers: route.request().headers() });
    await route.fulfill({ response, headers: { ...response.headers(), 'access-control-allow-origin': '*' } });
  });
}

test('every public page on a second theme is accessible, in the layouts it has and those it lacks', async ({ page, request }) => {
  test.setTimeout(300_000);
  const urls = await publicPages(request, THEMED);
  for (const path of ['/landing-page', '/sidebar-page', '/full-width-page']) {
    expect(urls).toContain(`${THEMED}${path}`);
  }
  for (const url of urls) {
    expect((await page.goto(url))?.status(), url).toBe(200);
    await expect(page.locator('h1'), url).toHaveCount(1);
    await expectNoViolations(page);
  }
});

test('the theme and storage screens and every theme preview are accessible', async ({ page }) => {
  test.setTimeout(180_000);
  await signIn(page, THEMED);
  for (const path of ['/admin/appearance', '/admin/appearance/colours', '/admin/settings/storage']) {
    expect((await page.goto(`${THEMED}${path}`))?.status(), path).toBe(200);
    await expectNoViolations(page);
  }
  await page.goto(`${THEMED}/admin/appearance`);
  const previews = await page.locator('main a[href^="/themes/"][href$="/index.html"]').evaluateAll((links) => links.map((link) => link.getAttribute('href') ?? ''));
  expect(previews.length, 'a preview of both installed themes').toBe(2);
  for (const preview of previews) {
    expect((await page.goto(`${THEMED}${preview}`))?.status(), preview).toBe(200);
    await expectNoViolations(page);
  }
});

test('with files in a bucket and a CDN, every public page is accessible and its files load', async ({ page, request }) => {
  test.setTimeout(300_000);
  await serveCdnFromSite(page);
  const failed: string[] = [];
  page.on('response', (response) => {
    if (response.url().startsWith(CDN) && response.status() >= 400) {
      failed.push(`${response.status()} ${response.url()}`);
    }
  });
  for (const url of await publicPages(request, CLOUD)) {
    expect((await page.goto(url, { waitUntil: 'load' }))?.status(), url).toBe(200);
    await expectNoViolations(page);
  }

  await page.goto(`${CLOUD}/every-block`, { waitUntil: 'load' });
  const picture = page.locator('.block-image img').first();
  expect(await picture.getAttribute('src')).toContain(`${CDN}/`);
  expect(await picture.evaluate((image: HTMLImageElement) => image.complete && image.naturalWidth > 0)).toBe(true);
  await expect(page.locator('video track[kind="captions"]')).toHaveCount(1);
  await expect(page.locator('.block-audio details')).toContainText('A single tone plays for two seconds.');
  expect(failed).toEqual([]);
});

test('the storage screen says the site uses its bucket and CDN', async ({ page }) => {
  await signIn(page, CLOUD);
  expect((await page.goto(`${CLOUD}/admin/settings/storage`))?.status()).toBe(200);
  await expect(page.locator('main')).toContainText('sightpress-a11y');
  await expect(page.locator('main')).toContainText(CDN);
  await expectNoViolations(page);
});
