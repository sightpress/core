// The M4 crawl (Q-1), on the `extended` copy of the demo site (tests/A11y/demo): a tenant whose organisation lets
// people at example.org register on its members-only sites, with text messages through a pretend provider. The demo
// site has the fixture module package `acme/events` installed and on (with one event), a custom role "Events team",
// two-step confirmation on and confirmed numbers for Ada and Max; the members-only "Riverside Staff" site on
// `localhost` has text messages off.
// - Every public page, the module's event page included, has no axe violations and is ready for search engines and AI
//   readers (title, description, canonical, valid JSON-LD with the module's `Event`, Markdown, llms.txt, sitemap).
// - Both sign-in pages: "Email address or mobile number" where text messages are on, "Email address" where they are
//   off, with the link to register with an organisation email on the members-only site; their error states too.
// - The phone field in every state (empty, refused, code, wrong code, set), the text message choices and
//   "Confirm it's you" (asked, code sent, wrong code, confirmed).
// - Every admin screen an admin reaches by following links, including Roles, the custom role, Text messages,
//   Security and the module's Events screen, and the screens someone with only the custom role reaches; none is
//   indexable. The new screens also pass in forced colours and reflow at 320px.
import { expect, test, type APIRequestContext, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { expectNoViolations } from './support/admin-site';

const SITE = 'http://127.0.0.1:8055';
const STAFF = 'http://localhost:8055';
const PASSWORD = 'Correct horse battery staple 7!';

test.describe.configure({ mode: 'serial' });

test.beforeAll(async ({ request }) => {
  test.setTimeout(240_000);
  await request.get(`${SITE}/_test/reset`);
  expect((await request.get(`${SITE}/`, { timeout: 230_000 })).status()).toBe(200);
});

async function signIn(page: Page, email: string, site: string = SITE, label = 'Email address or mobile number'): Promise<void> {
  await page.goto(`${site}/login`);
  await page.getByLabel(label).fill(email);
  await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await page.waitForLoadState('load');
  // Two-step confirmation is on: someone without a number is asked to add one first, and may skip it.
  if (new URL(page.url()).pathname === '/account/phone') {
    await page.getByRole('button', { name: 'Skip for now' }).click();
    await page.waitForLoadState('load');
  }
}

async function latestText(request: APIRequestContext, to: string): Promise<string> {
  const response = await request.get(`${SITE}/_test/texts?to=${encodeURIComponent(to)}`);
  expect(response.status(), `a text to ${to}`).toBe(200);
  const text = (await response.json() as { text: string }).text;
  expect(text, 'no link in a text message').not.toMatch(/https?:|www\./);

  return text;
}

function code(text: string): string {
  const found = /(\d{3}) (\d{3})/.exec(text);
  expect(found, text).not.toBeNull();

  return `${found?.[1]}${found?.[2]}`;
}

async function expectNotIndexable(page: Page, path: string, site: string = SITE): Promise<void> {
  const response = await page.goto(`${site}${path}`);
  expect(response?.status(), path).toBe(200);
  expect(response?.headers()['x-robots-tag'] ?? '', path).toContain('noindex');
  await expectNoViolations(page);
}

test('every public page, the module’s too, is accessible and ready for search engines and AI readers', async ({ page, request }) => {
  test.setTimeout(300_000);
  const sitemap = await (await request.get(`${SITE}/sitemap.xml`)).text();
  const urls = [...sitemap.matchAll(/<loc>([^<]+)<\/loc>/g)].map((match) => match[1]);
  expect(urls).toContain(`${SITE}/every-block`);
  expect(urls, 'the module adds its event to the sitemap').toContain(`${SITE}/events/book-fair`);
  const llms = await (await request.get(`${SITE}/llms.txt`)).text();

  for (const url of urls) {
    const response = await page.goto(url);
    expect(response?.status(), url).toBe(200);
    expect(response?.headers()['x-robots-tag'] ?? '', url).not.toContain('noindex');
    await expectNoViolations(page);
    await expect(page.locator('title'), url).toHaveCount(1);
    expect((await page.title()).trim(), url).not.toBe('');
    await expect(page.locator('h1'), url).toHaveCount(1);
    expect((await page.locator('meta[name="description"]').getAttribute('content'))?.trim() ?? '', `${url} has a description`).not.toBe('');
    expect(await page.locator('link[rel="canonical"]').getAttribute('href'), url).toBe(url);
    for (const script of await page.locator('script[type="application/ld+json"]').allTextContents()) {
      expect(() => JSON.parse(script), `${url} has valid JSON-LD`).not.toThrow();
    }
    const markdown = await page.locator('head link[rel="alternate"][type="text/markdown"]:not([hreflang])').getAttribute('href');
    expect(markdown, `${url} has a Markdown version`).not.toBeNull();
    expect(llms, `${url} is in llms.txt`).toContain(`(${markdown})`);
    expect((await request.get(markdown as string)).status(), `${markdown}`).toBe(200);
  }

  await page.goto(`${SITE}/events/book-fair`);
  const graphs = (await page.locator('script[type="application/ld+json"]').allTextContents()).join('\n');
  expect(graphs, 'the module’s Event JSON-LD').toContain('"Event"');
  expect(graphs).toContain('Book fair');
});

test('both sign-in pages and their errors are accessible and never indexable', async ({ page }) => {
  test.setTimeout(240_000);
  // Text messages on: email address or mobile number.
  await expectNotIndexable(page, '/login');
  await expect(page.getByLabel('Email address or mobile number')).toBeVisible();
  await page.getByLabel('Email address or mobile number').fill('07700 900999');
  await page.getByLabel('Password', { exact: true }).fill('not the password');
  await page.getByRole('button', { name: 'Sign in' }).press('Enter');
  await expect(page.getByRole('alert').or(page.locator('.error-summary')).first()).toBeVisible();
  await expectNoViolations(page);
  for (const path of ['/login/forgot', '/register']) {
    await expectNotIndexable(page, path);
  }

  // Text messages off, members-only, organisation registration: email address only, and the registration link.
  await expectNotIndexable(page, '/login', STAFF);
  await expect(page.getByLabel('Email address', { exact: true })).toBeVisible();
  await expect(page.getByLabel('Email address or mobile number')).toHaveCount(0);
  await expect(page.getByText('This site is for its members. Sign in to read it.')).toBeVisible();
  await page.getByRole('link', { name: 'Register with your organisation email' }).click();
  await expect(page).toHaveURL(`${STAFF}/register`);
  await expectNotIndexable(page, '/register', STAFF);
  await page.getByLabel('Email address').fill('someone@elsewhere.example');
  await page.keyboard.press('Enter');
  await expect(page.locator('main')).toContainText('example.org');
  await expectNoViolations(page);
});

test('the members-only site shows nothing to anyone who is not signed in', async ({ page }) => {
  const robots = await page.request.get(`${STAFF}/robots.txt`);
  expect(await robots.text()).toContain('Disallow: /\n');
  for (const path of ['/', '/sitemap.xml', '/llms.txt', '/index.md']) {
    const response = await page.request.get(`${STAFF}${path}`, { maxRedirects: 0 });
    expect(response.status(), path).toBe(302);
  }
});

test('the phone field, the text message choices and “Confirm it’s you” are accessible in every state', async ({ page, request }) => {
  test.setTimeout(240_000);
  // Bea has no number yet.
  await signIn(page, 'bea@example.org');
  await expectNotIndexable(page, '/account/phone');
  await expect(page.getByLabel('Country')).toHaveValue('GB');
  await page.getByLabel('Mobile number').fill('020 7946 0000');
  await page.getByLabel('Mobile number').press('Enter');
  await expect(page.locator('#phone-errors')).toBeFocused();
  await expect(page.getByLabel('Mobile number')).toHaveAttribute('aria-invalid', 'true');
  await expectNoViolations(page);
  await page.getByLabel('Mobile number').fill('07400 123402');
  await page.getByLabel('Mobile number').press('Enter');
  await expect(page.getByLabel('6-digit code')).toHaveAttribute('autocomplete', 'one-time-code');
  await expectNoViolations(page);
  await page.getByLabel('6-digit code').fill('000000');
  await page.keyboard.press('Enter');
  await expect(page.getByLabel('6-digit code')).toHaveAttribute('aria-invalid', 'true');
  await expectNoViolations(page);
  await page.getByLabel('6-digit code').fill(code(await latestText(request, '+447400123402')));
  await page.keyboard.press('Enter');
  await expect(page.getByRole('status')).toHaveText('Your mobile number is now 07400 123402.');
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Your mobile number');
  await expect(page.getByLabel('Send me text messages')).toBeVisible();
  await expectNoViolations(page);
  await expectNotIndexable(page, '/account');

  // Max has a number; a change of email address is critical and waits for a code.
  await page.context().clearCookies();
  await signIn(page, 'max@example.org');
  await page.goto(`${SITE}/account/email`);
  await page.getByLabel('New email address').fill('max@example.org.uk');
  await page.getByLabel('Current password').fill(PASSWORD);
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(`${SITE}/confirm`);
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Confirm it’s you');
  expect((await page.request.get(`${SITE}/confirm`)).headers()['x-robots-tag'] ?? '').toContain('noindex');
  await expectNoViolations(page);
  await page.getByRole('button', { name: 'Send me a code' }).press('Enter');
  await expect(page.getByLabel('6-digit code')).toBeVisible();
  await expectNoViolations(page);
  await page.getByLabel('6-digit code').fill('000000');
  await page.keyboard.press('Enter');
  await expect(page.getByLabel('6-digit code')).toHaveAttribute('aria-invalid', 'true');
  await expectNoViolations(page);
  await page.getByLabel('6-digit code').fill(code(await latestText(request, '+447400123404')));
  await page.keyboard.press('Enter');
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('You confirmed it’s you');
  await expectNoViolations(page);
});

// Every kind of admin screen an admin reaches by following links; a screen that drops out of the crawl fails here.
const ADMIN_SCREENS = [
  '/account', '/admin', '/admin/analytics', '/admin/analytics/delete', '/admin/appearance', '/admin/appearance/colours', '/admin/audit', '/admin/events', '/admin/features',
  '/admin/media', '/admin/menus', '/admin/menus/{id}', '/admin/media/{id}', '/admin/media/{id}/delete',
  '/admin/pages', '/admin/pages/new', '/admin/pages/{id}', '/admin/pages/{id}/revisions', '/admin/pages/{id}/revisions/{id}', '/admin/redirects', '/admin/roles', '/admin/roles/new', '/admin/roles/{name}',
  '/admin/settings', '/admin/settings/branding', '/admin/settings/languages', '/admin/settings/security', '/admin/settings/sign-up', '/admin/settings/storage', '/admin/sms', '/admin/users', '/admin/users/invite', '/admin/users/{id}',
];

async function crawl(page: Page, limit: number): Promise<Set<string>> {
  const seen = new Set<string>();
  const queue = ['/admin', '/account'];
  let visited = 0;
  while (queue.length > 0 && visited < limit) {
    const path = queue.shift() as string;
    const kind = path.replace(/\/\d+/g, '/{id}').replace(/^\/admin\/roles\/(?!new$)[a-z][a-z0-9_]*/, '/admin/roles/{name}');
    if (seen.has(kind)) {
      continue;
    }
    seen.add(kind);
    const response = await page.goto(`${SITE}${path}`);
    expect(response?.status(), path).toBe(200);
    expect(response?.headers()['x-robots-tag'] ?? '', path).toContain('noindex');
    await expectNoViolations(page);
    visited++;
    for (const href of await page.locator('main a[href^="/admin"], nav[aria-label="Admin"] a[href^="/admin"]').evaluateAll(
      (links) => links.map((link) => link.getAttribute('href') ?? ''),
    )) {
      const target = href.split('#')[0];
      if (target !== '' && !target.endsWith('.csv') && !target.includes('?')) {
        queue.push(target);
      }
    }
  }

  return seen;
}

test('every admin screen an admin reaches, the M4 ones included, is accessible and never indexable', async ({ page }) => {
  test.setTimeout(420_000);
  await signIn(page, 'ada@example.org');
  expect([...(await crawl(page, 50))].sort()).toEqual([...ADMIN_SCREENS].sort());

  await page.goto(`${SITE}/admin/roles`);
  await expect(page.locator('main')).toContainText('Events team');
  await page.goto(`${SITE}/admin/features`);
  await expect(page.locator('main')).toContainText('acme.events');
});

test('the new screens work in forced colours and reflow at 320px', async ({ page }) => {
  test.setTimeout(240_000);
  await signIn(page, 'ada@example.org');
  const screens = ['/admin/roles', '/admin/roles/new', '/admin/roles/events_team', '/admin/sms', '/admin/settings/security', '/admin/events', '/admin/features', '/account/phone'];
  for (const path of screens) {
    await page.emulateMedia({ forcedColors: 'active' });
    await page.goto(`${SITE}${path}`);
    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
    expect(results.violations, `${path} in forced colours`).toEqual([]);
    await page.emulateMedia({ forcedColors: 'none' });
    await page.setViewportSize({ width: 320, height: 640 });
    await page.reload();
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow, `${path}: no sideways scrolling at 320px`).toBeLessThanOrEqual(0);
    await page.setViewportSize({ width: 1280, height: 720 });
  }
  for (const path of ['/login', '/register']) {
    await page.context().clearCookies();
    await page.emulateMedia({ forcedColors: 'active' });
    await page.goto(`${STAFF}${path}`);
    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
    expect(results.violations, `${STAFF}${path} in forced colours`).toEqual([]);
  }
  await page.emulateMedia({ forcedColors: 'none' });
});

test('someone with only the custom role reaches only what it allows', async ({ page, request }) => {
  test.setTimeout(180_000);
  // Ada gives Cleo the custom role on Cleo's page. Changing a role is critical: it waits for a code.
  await signIn(page, 'ada@example.org');
  await page.goto(`${SITE}/admin/users`);
  await page.getByRole('link', { name: 'Cleo' }).click();
  await page.getByRole('group', { name: 'Role' }).getByRole('radio', { name: /^Events team/ }).check();
  await page.getByRole('button', { name: 'Change role' }).click();
  await expect(page).toHaveURL(`${SITE}/confirm`);
  await page.getByRole('button', { name: 'Send me a code' }).click();
  await page.getByLabel('6-digit code').fill(code(await latestText(request, '+447400123401')));
  await page.keyboard.press('Enter');
  await page.getByRole('button', { name: 'Make the change' }).click();
  await expect(page.getByRole('status')).toHaveText('Cleo’s role is now Events team.');
  await page.context().clearCookies();

  await signIn(page, 'cleo@example.org');
  const reached = await crawl(page, 20);
  expect(reached.has('/admin/events'), 'the custom role manages events').toBe(true);
  expect(reached.has('/admin/pages'), 'but not pages').toBe(false);
  expect(reached.has('/admin/roles'), 'nor roles').toBe(false);
  expect((await page.goto(`${SITE}/admin/roles`))?.status()).toBe(403);
});
