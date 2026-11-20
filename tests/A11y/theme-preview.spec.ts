// The static preview page of a theme (theme:preview, T-5), made from theme-interface's page.json with the default
// theme: it opens from file:// with no request leaving its folder, shows every section in order, has no axe
// violations in light, dark and forced-colors mode at full width, 320 px and 200 % zoom, and works by keyboard
// (skip link, navbar, Download, then every control).
// The folder is built with tests/A11y/preview/build.php, or taken from SIGHTPRESS_THEME_PREVIEW when that is set
// (for a PHP without GD on the host: build it in the dev image first).
import { execFileSync } from 'node:child_process';
import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { expect, test, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const WCAG_22_AA = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const SECTIONS = ['Text and buttons', 'Pictures and files', 'Side by side', 'Sound and video', 'Contact'];
let folder = process.env.SIGHTPRESS_THEME_PREVIEW ?? '';

test.beforeAll(() => {
  if (folder === '') {
    folder = path.join(mkdtempSync(path.join(tmpdir(), 'sp-preview-')), 'default');
    execFileSync('php', ['tests/A11y/preview/build.php', folder], { stdio: 'inherit' });
  }
});

async function open(page: Page): Promise<string[]> {
  const root = pathToFileURL(path.resolve(folder)).href + '/';
  const outside: string[] = [];
  await page.route('**/*', (route) => {
    const url = route.request().url();
    if (url.startsWith(root)) {
      return route.continue();
    }
    outside.push(url);
    return route.abort();
  });
  await page.goto(root + 'index.html');
  return outside;
}

test('opens offline with every section in order and no request leaving the folder', async ({ page }) => {
  const outside = await open(page);

  await expect(page.getByRole('heading', { level: 1 })).toHaveText('SightPress Default');
  await expect(page.getByRole('heading', { level: 2 })).toHaveText(SECTIONS);
  const nav = page.getByRole('navigation', { name: 'Main' });
  await expect(nav.getByRole('link')).toHaveText(SECTIONS);
  await expect(page.getByRole('link', { name: 'Download SightPress Default' })).toHaveText('Download');
  for (const selector of ['.block-call-to-action', '.block-image', '.block-file-download', '.block-columns', '.block-gallery', '.block-audio', '.block-video']) {
    await expect(page.locator(selector).first(), selector).toBeVisible();
  }
  await expect(page.getByText('This is a preview: the form doesn\'t send anything.')).toBeVisible();
  for (const image of await page.locator('img').all()) {
    expect(await image.evaluate((el: HTMLImageElement) => el.complete && el.naturalWidth > 0)).toBe(true);
  }
  await page.getByRole('link', { name: 'Contact' }).click();
  await expect(page).toHaveURL(/#contact$/);
  expect(outside).toEqual([]);
});

for (const [name, viewport] of [['full width', { width: 1280, height: 800 }], ['320 px', { width: 320, height: 640 }], ['200 % zoom', { width: 640, height: 400 }]] as const) {
  test(`no axe violations in light, dark and forced-colors mode at ${name}`, async ({ page }) => {
    await page.setViewportSize(viewport);
    await open(page);
    for (const media of [{ colorScheme: 'light' }, { colorScheme: 'dark' }, { forcedColors: 'active' }] as const) {
      await page.emulateMedia(media);
      const results = await new AxeBuilder({ page }).withTags(WCAG_22_AA).analyze();
      expect(results.violations, JSON.stringify(media)).toEqual([]);
    }
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(0);
  });
}

test('works by keyboard: skip link, navbar, Download, then every control', async ({ page }) => {
  await open(page);

  await page.keyboard.press('Tab');
  await expect(page.getByRole('link', { name: 'Skip to main content' })).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(page.getByRole('link', { name: 'Riverside Reading Room' })).toBeFocused();
  for (const section of SECTIONS) {
    await page.keyboard.press('Tab');
    await expect(page.getByRole('navigation', { name: 'Main' }).getByRole('link', { name: section })).toBeFocused();
  }
  // The header's search form, then the hero.
  await page.keyboard.press('Tab');
  await expect(page.getByRole('searchbox')).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(page.getByRole('button', { name: 'Search' })).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(page.getByRole('link', { name: 'Download SightPress Default' })).toBeFocused();

  // Every visible control in <main> (not the fallback links inside <audio> and <video>) gets a number; Tab must reach each.
  const controls = await page.evaluate(() => {
    const all = [...document.querySelectorAll('main a[href], main button, main input:not([type="hidden"]), main textarea, main select, main summary, main audio[controls], main video[controls]')]
      .filter((el) => el.checkVisibility() && !el.parentElement?.closest('audio, video'));
    all.forEach((el, i) => el.setAttribute('data-preview-control', String(i)));
    return all.length;
  });
  const reached = new Set<string>();
  for (let i = 0; i < 80; i++) {
    await page.keyboard.press('Tab');
    const index = await page.evaluate(() => document.activeElement?.getAttribute('data-preview-control') ?? '');
    if (index !== '') {
      reached.add(index);
    }
  }
  expect(controls).toBeGreaterThan(10);
  expect(reached.size).toBe(controls);
});
