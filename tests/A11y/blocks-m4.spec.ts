// The M4 blocks on the demo site (BL-2): the map embed loads nothing from OpenStreetMap until the reader presses its
// button, and then shows the map in a frame that gets focus; without JavaScript the link to the map is there. The list
// of pages is a real list with one link per page, and never shows the members-only page to guests: not in the HTML,
// the Markdown version, llms.txt or the JSON-LD. The sign language video names its language. No axe violations.
import { expect, test, type Request } from '@playwright/test';
import { expectNoViolations } from './support/admin-site';

const SITE = 'http://127.0.0.1:8056';
const PROVIDERS = /(^|\.)(openstreetmap\.org|youtube-nocookie\.com|youtube\.com|vimeo\.com|ytimg\.com|vimeocdn\.com)$/;

test.describe.configure({ mode: 'serial' });

test.beforeAll(async ({ request }) => {
  test.setTimeout(180_000);
  expect((await request.get(`${SITE}/every-block`, { timeout: 170_000 })).status()).toBe(200);
});

test('the map loads nothing from its provider until the reader asks for it', async ({ page }) => {
  const outside: string[] = [];
  page.on('request', (request: Request) => {
    const host = new URL(request.url()).hostname;
    if (PROVIDERS.test(host)) {
      outside.push(request.url());
    }
  });
  await page.route(PROVIDERS, (route) => route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>Map</title><p>Map</p>' }));

  await page.goto(`${SITE}/every-block`);
  const embed = page.locator('.block-embed');
  await expect(embed.getByText('This map is on OpenStreetMap.')).toBeVisible();
  const button = embed.getByRole('button', { name: 'Show the map: Map of the streets around the demo office' });
  await expect(button).toBeVisible();
  await expect(embed.getByRole('link', { name: 'Open the map on OpenStreetMap: Map of the streets around the demo office' })).toBeVisible();
  await expect(page.locator('iframe')).toHaveCount(0);
  await page.waitForLoadState('networkidle');
  expect(outside).toEqual([]);
  await expectNoViolations(page);

  await button.focus();
  await page.keyboard.press('Enter');
  const frame = embed.locator('iframe');
  await expect(frame).toHaveAttribute('title', 'Map of the streets around the demo office');
  await expect(frame).toBeFocused();
  expect(outside.length).toBeGreaterThan(0);
  expect(new URL(outside[0]).origin).toBe('https://www.openstreetmap.org');
});

test('without JavaScript the map is a link and nothing is loaded', async ({ browser }) => {
  const context = await browser.newContext({ javaScriptEnabled: false });
  const page = await context.newPage();
  const outside: string[] = [];
  page.on('request', (request: Request) => {
    if (PROVIDERS.test(new URL(request.url()).hostname)) {
      outside.push(request.url());
    }
  });
  await page.goto(`${SITE}/every-block`);
  await expect(page.getByRole('link', { name: 'Open the map on OpenStreetMap: Map of the streets around the demo office' })).toBeVisible();
  await expect(page.getByRole('button', { name: /Show the map/ })).toBeHidden();
  expect(outside).toEqual([]);
  await context.close();
});

test('the list of pages never shows the members-only page to guests', async ({ page, request }) => {
  await page.goto(`${SITE}/every-block`);
  const list = page.locator('ul.block-page-list');
  await expect(list).toBeVisible();
  const items = list.getByRole('listitem');
  expect(await items.count()).toBeGreaterThan(0);
  for (const item of await items.all()) {
    await expect(item.getByRole('link')).toHaveCount(1);
  }
  await expect(list.getByRole('link', { name: 'For members' })).toHaveCount(0);
  await expect(list.getByRole('link', { name: 'A landing page' })).toBeVisible();
  await expectNoViolations(page);

  const html = await (await request.get(`${SITE}/every-block`)).text();
  const jsonLd = [...html.matchAll(/<script type="application\/ld\+json"[^>]*>([\s\S]*?)<\/script>/g)].map((match) => match[1]).join('');
  expect(jsonLd).toContain('ItemList');
  expect(jsonLd).not.toContain('for-members');
  expect(html).not.toContain('/for-members');
  for (const path of ['/every-block.md', '/llms.txt', '/llms-full.txt']) {
    const text = await (await request.get(`${SITE}${path}`)).text();
    expect(text, path).not.toContain('For members');
    expect(text, path).not.toContain('/for-members');
  }
  const markdown = await (await request.get(`${SITE}/every-block.md`)).text();
  expect(markdown).toContain('[A landing page]');
});

test('the sign language video names its language and has captions', async ({ page }) => {
  await page.goto(`${SITE}/every-block`);
  const figure = page.locator('.block-sign-language-video');
  await expect(figure).toHaveAttribute('data-sign-language', 'bfi');
  await expect(figure.locator('figcaption')).toHaveText('Test pattern, signed (British Sign Language (BSL))');
  await expect(figure.locator('track[kind=captions]')).toHaveCount(1);
});
