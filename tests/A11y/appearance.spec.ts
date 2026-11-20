// The theme picker (T-2) on the admin test site, which has a second theme ("Harbour") installed and its preview
// published: an admin applies it by keyboard, opens its preview (a new tab, said in the link), changes a colour of the
// site's theme, sees a low-contrast colour refused with the pair and the ratio in words while the form keeps what was
// typed, saves a good one and goes back to the theme's own colours. Statuses are words, never colour alone. No axe
// violations in light and dark mode, in forced colours or at 320 px.
import { expect, test } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { ADMIN_SITES, expectNoViolations, signIn } from './support/admin-site';

const SITE = ADMIN_SITES.appearance;

test.beforeEach(async ({ request }) => {
  await request.get(`${SITE}/_test/reset`);
  await request.get(`${SITE}/login`);
  expect((await request.get(`${SITE}/_test/publish`)).status()).toBe(200);
});

async function expectNoViolationsAnywhere(page: import('@playwright/test').Page): Promise<void> {
  await expectNoViolations(page);
  await page.emulateMedia({ colorScheme: 'light', forcedColors: 'active' });
  expect((await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze()).violations, 'forced colours').toEqual([]);
  await page.emulateMedia({ forcedColors: 'none' });
  await page.setViewportSize({ width: 320, height: 800 });
  expect((await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze()).violations, '320 px').toEqual([]);
  expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(0);
  await page.setViewportSize({ width: 1280, height: 800 });
}

test('an admin applies a theme and previews it by keyboard', async ({ page, context }) => {
  await signIn(page, SITE, 'ada@example.org', '/admin/appearance');
  await expect(page).toHaveTitle('Appearance - Admin - Riverside Library');
  const harbour = page.locator('section', { has: page.getByRole('heading', { level: 2, name: 'Harbour' }) });
  const standard = page.locator('section', { has: page.getByRole('heading', { level: 2, name: 'SightPress Default' }) });
  await expect(standard.getByText('Status: The site’s theme')).toBeVisible();
  await expect(harbour.getByText('Status: Available')).toBeVisible();
  await expect(harbour.getByRole('img', { name: 'A dark blue top bar and a large white heading.' })).toHaveCount(0);
  await expect(harbour.getByText('What it looks like: A dark blue top bar and a large white heading.')).toBeVisible();
  await expectNoViolationsAnywhere(page);

  const preview = harbour.getByRole('link', { name: 'Preview Harbour (opens in a new tab)' });
  await expect(preview).toHaveAttribute('target', '_blank');
  await preview.focus();
  const [tab] = await Promise.all([context.waitForEvent('page'), page.keyboard.press('Enter')]);
  await tab.waitForLoadState();
  await expect(tab.getByRole('heading', { level: 1 })).toHaveText('Harbour');
  await tab.close();

  await harbour.getByRole('button', { name: 'Use Harbour' }).focus();
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(`${SITE}/admin/appearance#theme-acme-harbour`);
  await expect(page.getByRole('status')).toHaveText('Harbour is now the site’s theme.');
  await expect(harbour.getByText('Status: The site’s theme')).toBeVisible();
  await expect(standard.getByRole('button', { name: 'Use SightPress Default' })).toBeVisible();
  await expectNoViolations(page);

  await standard.getByRole('button', { name: 'Use SightPress Default' }).focus();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('status')).toHaveText('SightPress Default is now the site’s theme.');
});

test('a low-contrast colour is refused in words, a good one is saved and the theme’s colours come back', async ({ page }) => {
  await signIn(page, SITE, 'ada@example.org', '/admin/appearance');
  await page.getByRole('link', { name: 'Change the colours of the site’s theme' }).focus();
  await page.keyboard.press('Enter');
  await expect(page).toHaveTitle('Colours - Admin - Riverside Library');
  await expect(page.getByText('The site uses the theme’s own colours.')).toBeVisible();
  await expectNoViolationsAnywhere(page);

  const light = page.getByRole('group', { name: 'Light mode' });
  const primary = light.getByLabel('Buttons and highlights');
  await primary.fill('#a0c4ff');
  await primary.press('Enter');
  await expect(page).toHaveTitle('1 error: Colours - Admin - Riverside Library');
  const summary = page.getByRole('heading', { name: 'There is a problem' });
  await expect(summary).toBeVisible();
  await expect(page.getByText(/on-primary on primary has a contrast of \d\.\d\d:1 in light mode; it needs at least 4\.5:1\./).first()).toBeVisible();
  await expect(primary).toHaveValue('#a0c4ff');
  await expect(primary).toHaveAttribute('aria-invalid', 'true');
  await expectNoViolations(page);

  await primary.fill('#004080');
  await primary.press('Enter');
  await expect(page.getByRole('status')).toHaveText('The colours are saved.');
  await expect(page.getByText('The site uses some colours of its own.')).toBeVisible();
  await expect(light.getByLabel('Buttons and highlights')).toHaveValue('#004080');
  expect(await (await page.request.get(`${SITE}/assets/tokens.css`)).text()).toContain('#004080');

  await page.getByRole('button', { name: 'Use the theme’s own colours' }).focus();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('status')).toHaveText('The site uses the colours of SightPress Default again.');
  await expect(page.getByText('The site uses the theme’s own colours.')).toBeVisible();
});

test('only people who apply themes see the screens', async ({ page }) => {
  await signIn(page, SITE, 'bea@example.org', '/admin');
  await expect(page.getByRole('navigation', { name: 'Admin' }).getByRole('link', { name: 'Appearance' })).toHaveCount(0);
  expect((await page.goto(`${SITE}/admin/appearance`))?.status()).toBe(403);
});

for (const [path, name] of [['/services', 'sidebar'], ['/summer', 'landing'], ['/hours', 'full width']] as const) {
  test(`the ${name} layout has no violations and works by keyboard`, async ({ page, request }) => {
    expect((await request.get(`${SITE}/_test/layouts`)).status()).toBe(204);
    await page.goto(`${SITE}${path}`);
    await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);
    await expectNoViolationsAnywhere(page);

    await page.keyboard.press('Tab');
    await expect(page.getByRole('link', { name: 'Skip to main content' })).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('main')).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(page.getByRole('main').getByRole('link', { name: 'a link home' })).toBeFocused();
    if (name === 'sidebar') {
      const aside = page.getByRole('complementary', { name: 'In this section' });
      await expect(aside).toBeVisible();
      await page.keyboard.press('Tab');
      await expect(aside.getByRole('link', { name: 'Lending' })).toBeFocused();
    }
  });
}

test('switching to a theme without some layouts lists their pages first, and switching back restores them', async ({ page, request }) => {
  expect((await request.get(`${SITE}/_test/layouts`)).status()).toBe(204);
  await signIn(page, SITE, 'ada@example.org', '/admin/appearance');
  await page.getByRole('button', { name: 'Use Harbour' }).focus();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Use Harbour?');
  const table = page.getByRole('table', { name: 'Pages shown in the standard layout with Harbour' });
  await expect(table.getByRole('row')).toHaveCount(3);
  await expect(table.getByRole('row', { name: 'Summer reading landing' })).toBeVisible();
  await expect(table.getByRole('row', { name: 'Opening hours full-width' })).toBeVisible();
  await expectNoViolations(page);

  await page.getByRole('button', { name: 'Use Harbour' }).focus();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('status')).toHaveText('Harbour is now the site’s theme.');
  await page.goto(`${SITE}/summer`);
  await expect(page.locator('.hero--landing')).toHaveCount(0);
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Summer reading');

  await page.goto(`${SITE}/admin/appearance`);
  await page.getByRole('button', { name: 'Use SightPress Default' }).focus();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('status')).toHaveText('SightPress Default is now the site’s theme.');
  await page.goto(`${SITE}/summer`);
  await expect(page.locator('.hero--landing')).toHaveCount(1);
});
