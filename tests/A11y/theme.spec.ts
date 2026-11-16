// The default theme (C-6.1) on the component gallery's style guide (/theme/home and /theme/default): no axe
// violations in light and dark mode, with and without "more contrast"; the skip link, dialog and disclosure work
// by keyboard; theme files are served with caching headers; the base font is at least 16px; the page reflows at 320px and at 200% zoom; motion is reduced on
// request.
import { expect, test, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const GALLERY = 'http://127.0.0.1:8099';
const WCAG_22_AA = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

async function expectNoViolations(page: Page): Promise<void> {
  for (const colorScheme of ['light', 'dark'] as const) {
    for (const contrast of ['no-preference', 'more'] as const) {
      await page.emulateMedia({ colorScheme, contrast });
      const results = await new AxeBuilder({ page }).withTags(WCAG_22_AA).analyze();
      expect(results.violations, `${colorScheme} mode, ${contrast} contrast`).toEqual([]);
    }
  }
}

async function expectNoHorizontalScroll(page: Page): Promise<void> {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(0);
}

for (const layout of ['home', 'default'] as const) {
  test(`the ${layout} layout has no violations in any colour mode`, async ({ page }) => {
    await page.goto(`${GALLERY}/theme/${layout}?saved=1`);

    await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);
    await expect(page.getByRole('banner')).toHaveCount(1);
    await expect(page.getByRole('main')).toHaveCount(1);
    await expect(page.getByRole('contentinfo')).toHaveCount(1);
    await expect(page.getByRole('navigation', { name: 'Main' })).toBeVisible();
    await expect(page.getByRole('navigation', { name: 'Footer' })).toBeVisible();
    await expect(page.getByRole('status').first()).toHaveText('Your changes have been saved.');
    await expect(page.locator('html')).toHaveAttribute('lang', 'en-GB');
    await expect(page.locator('html')).toHaveAttribute('dir', 'ltr');
    await expectNoViolations(page);
  });
}

test('the skip link is the first stop, shows on focus and moves to the main content', async ({ page }) => {
  await page.goto(`${GALLERY}/theme/default`);

  await page.keyboard.press('Tab');
  const skip = page.getByRole('link', { name: 'Skip to main content' });
  await expect(skip).toBeFocused();
  await expect(skip).toBeInViewport();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('main')).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(page.getByRole('link', { name: 'link that is always underlined' })).toBeFocused();
});

test('focus is always visible', async ({ page }) => {
  await page.goto(`${GALLERY}/theme/default`);

  for (const name of ['Save draft', 'Preview', 'Delete page']) {
    await page.getByRole('button', { name }).focus();
    const outline = await page.getByRole('button', { name }).evaluate((el) => getComputedStyle(el).outlineStyle);
    expect(outline, name).toBe('solid');
  }
  const card = page.getByRole('link', { name: 'Opening hours' });
  await card.focus();
  const ring = await card.evaluate((el) => getComputedStyle(el, '::after').outlineStyle);
  expect(ring).toBe('solid');
});

test('the dialog opens and closes by keyboard and focus goes back to its button', async ({ page }) => {
  await page.goto(`${GALLERY}/theme/default`);

  const opener = page.getByRole('button', { name: 'How do I choose a layout?' });
  await opener.focus();
  await page.keyboard.press('Enter');
  const dialog = page.getByRole('dialog', { name: 'Choosing a layout' });
  await expect(dialog).toBeVisible();
  await expect(dialog.getByRole('button', { name: 'Close' })).toBeFocused();
  const results = await new AxeBuilder({ page }).withTags(WCAG_22_AA).analyze();
  expect(results.violations).toEqual([]);

  await page.keyboard.press('Escape');
  await expect(dialog).toBeHidden();
  await expect(opener).toBeFocused();

  await page.keyboard.press('Enter');
  await expect(dialog).toBeVisible();
  await page.keyboard.press('Enter');
  await expect(dialog).toBeHidden();
  await expect(opener).toBeFocused();
});

test('the disclosure opens and closes by keyboard', async ({ page }) => {
  await page.goto(`${GALLERY}/theme/default`);

  const summary = page.getByText('What happens to my message?');
  const answer = page.getByText('We keep the messages you send us for 30 days, then delete them.');
  await summary.focus();
  await expect(answer).toBeHidden();
  await page.keyboard.press('Enter');
  await expect(answer).toBeVisible();
  await page.keyboard.press('Space');
  await expect(answer).toBeHidden();
});

test('the scrolling table can be reached and scrolled by keyboard', async ({ page }) => {
  await page.setViewportSize({ width: 320, height: 800 });
  await page.goto(`${GALLERY}/theme/default`);

  const region = page.getByRole('region', { name: 'Opening hours this week' });
  await region.focus();
  await expect(region).toBeFocused();
  await page.keyboard.press('ArrowRight');
  await expect.poll(() => region.evaluate((el) => el.scrollLeft)).toBeGreaterThan(0);
});

test('the base font is at least 16px', async ({ page }) => {
  await page.goto(`${GALLERY}/theme/default`);

  const size = await page.locator('body').evaluate((el) => parseFloat(getComputedStyle(el).fontSize));
  expect(size).toBeGreaterThanOrEqual(16);
});

test('the page reflows at 320px without scrolling sideways', async ({ page }) => {
  await page.setViewportSize({ width: 320, height: 640 });
  for (const path of ['/theme/home', '/theme/default', '/form']) {
    await page.goto(`${GALLERY}${path}`);
    await expectNoHorizontalScroll(page);
  }
  await expectNoViolations(page);
});

test('at 200% zoom the page still fits and the menu stays on screen', async ({ browser }) => {
  // 200% zoom of a 1280px window: the layout sees a 640px viewport and every CSS pixel is two device pixels.
  const context = await browser.newContext({ viewport: { width: 640, height: 400 }, deviceScaleFactor: 2 });
  const page = await context.newPage();
  await page.goto(`${GALLERY}/theme/default`);

  await expectNoHorizontalScroll(page);
  await expect(page.getByRole('navigation', { name: 'Main' }).getByRole('link', { name: 'Form' })).toBeInViewport();
  await context.close();
});

test('with reduced motion nothing animates', async ({ page }) => {
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await page.goto(`${GALLERY}/theme/default`);

  const behaviour = await page.locator('html').evaluate((el) => getComputedStyle(el).scrollBehavior);
  expect(behaviour).toBe('auto');
  const duration = await page.getByRole('link', { name: 'Skip to main content' }).evaluate((el) => getComputedStyle(el).transitionDuration);
  expect(parseFloat(duration)).toBeLessThan(0.001);
});

test('theme files are served with caching headers, and theme.json is not', async ({ request }) => {
  const css = await request.get(`${GALLERY}/assets/css/site.css?v=1`);
  expect(css.status()).toBe(200);
  expect(css.headers()['cache-control']).toBe('public, max-age=31536000, immutable');
  expect((await request.get(`${GALLERY}/assets/tokens.css`)).status()).toBe(200);
  expect((await request.get(`${GALLERY}/assets/..%2Ftheme.json`)).status()).not.toBe(200);
});
