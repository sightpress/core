// The content blocks (C-4.2, C-4.3) on a real site (tests/A11y/blocks/index.php), served by the public page router
// (C-6.2): the home page and a page with every core block type have no axe violations in light and dark mode, reflow
// at 320 px, work by keyboard in reading order, and the contact form announces its errors and its result.
import { expect, test, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const SITE = 'http://127.0.0.1:8097';
const WCAG_22_AA = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

async function expectNoViolations(page: Page): Promise<void> {
  for (const colorScheme of ['light', 'dark'] as const) {
    await page.emulateMedia({ colorScheme });
    const results = await new AxeBuilder({ page }).withTags(WCAG_22_AA).analyze();
    expect(results.violations, `${colorScheme} mode`).toEqual([]);
  }
}

/** Presses Tab until `last` has focus and returns a short description of everything that got focus on the way. */
async function tabUntil(page: Page, last: string, limit = 60): Promise<string[]> {
  const seen: string[] = [];
  for (let i = 0; i < limit; i++) {
    await page.keyboard.press('Tab');
    const name = await page.evaluate(() => {
      const element = document.activeElement as HTMLElement | null;
      if (element === null) {
        return '';
      }
      const label = element.id ? document.querySelector(`label[for="${element.id}"]`)?.textContent : null;
      return `${element.tagName.toLowerCase()}:${(label ?? element.textContent ?? '').replace(/\s+/g, ' ').trim()}`;
    });
    seen.push(name);
    if (name === last) {
      return seen;
    }
  }
  throw new Error(`"${last}" never got focus; focus went through: ${seen.join(' | ')}`);
}

test.beforeEach(async ({ request }) => {
  await request.get(`${SITE}/_test/reset`);
});

test('the home page is at / in the home layout, and a missing page is a 404', async ({ page }) => {
  const response = await page.goto(`${SITE}/`);

  expect(response?.status()).toBe(200);
  await expect(page).toHaveTitle('Welcome to Riverside Library - Riverside Library');
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Welcome to Riverside Library');
  await expect(page.getByText('Books, music and a view of the harbour.')).toBeVisible();
  await expectNoViolations(page);

  await page.getByRole('link', { name: 'visit to the library' }).click();
  await expect(page).toHaveURL(`${SITE}/visit`);

  expect((await page.goto(`${SITE}/home`))?.url()).toBe(`${SITE}/`);
  expect((await page.goto(`${SITE}/nowhere`))?.status()).toBe(404);
  await expectNoViolations(page);
});

test('the main menu opens its submenu with a disclosure button, and the breadcrumbs lead back', async ({ page }) => {
  await page.goto(`${SITE}/`);

  const menu = page.getByRole('navigation', { name: 'Main' });
  const toggle = menu.getByRole('button', { name: 'Show the pages under Visit the library' });
  await expect(toggle).toHaveAttribute('aria-expanded', 'false');
  await expect(menu.getByRole('link', { name: 'Opening times' })).toBeHidden();
  await expectNoViolations(page);

  await page.getByRole('link', { name: 'Skip to main content' }).focus();
  await page.keyboard.press('Tab');
  await page.keyboard.press('Tab');
  await expect(menu.getByRole('link', { name: 'Visit the library' })).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(toggle).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(toggle).toHaveAttribute('aria-expanded', 'true');
  await page.keyboard.press('Tab');
  await expect(menu.getByRole('link', { name: 'Opening times' })).toBeFocused();
  await expectNoViolations(page);
  await page.keyboard.press('Escape');
  await expect(toggle).toBeFocused();
  await expect(toggle).toHaveAttribute('aria-expanded', 'false');

  await page.keyboard.press('Enter');
  await menu.getByRole('link', { name: 'Opening times' }).click();
  await expect(page).toHaveURL(`${SITE}/visit/opening-times`);
  await expect(menu.getByRole('link', { name: 'Opening times' })).toHaveAttribute('aria-current', 'page');
  await expect(toggle).toHaveAttribute('aria-expanded', 'true');
  const crumbs = page.getByRole('navigation', { name: 'Breadcrumb' });
  await expect(crumbs.getByRole('link')).toHaveText(['Home', 'Visit the library', 'Opening times']);
  await expect(crumbs.getByRole('link', { name: 'Opening times' })).toHaveAttribute('aria-current', 'page');
  await expect(page.getByRole('navigation', { name: 'Footer' }).getByRole('link', { name: 'Contact us' })).toBeVisible();
  await expectNoViolations(page);

  await page.setViewportSize({ width: 320, height: 640 });
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
  await expectNoViolations(page);
});

test('a page with every block type has no violations and reflows at 320 px', async ({ page }) => {
  await page.goto(`${SITE}/visit`);

  await expect(page).toHaveTitle('Visit the library - Riverside Library');
  await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);
  await expect(page.getByRole('img', { name: 'The harbour seen from the reading room' })).toBeVisible();
  await expect(page.getByRole('list').filter({ has: page.getByRole('img', { name: 'Boats in the harbour' }) })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Annual report 2025 (PDF, 77 B)' })).toBeVisible();
  await expect(page.getByRole('form', { name: 'Contact form' })).toHaveCount(0);
  await expect(page.getByRole('region', { name: 'Contact form' })).toBeVisible();
  await expect(page.getByText('office@example.org')).toHaveCount(0);
  await expectNoViolations(page);

  await page.setViewportSize({ width: 320, height: 640 });
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
  await expectNoViolations(page);
});

test('everything works by keyboard in reading order, and the hidden spam field never gets focus', async ({ page }) => {
  await page.goto(`${SITE}/visit`);

  const seen = await tabUntil(page, 'button:Send message');
  const order = [
    'a:who we are',
    'a:Book a visit',
    'summary:Transcript: A tour of the library',
    'summary:Transcript: Opening talk',
    'a:Annual report 2025 (PDF, 77 B)',
    'input:Your name',
    'input:Your email address',
    'textarea:Your message',
    'button:Send message',
  ];
  expect(seen.filter((name) => order.includes(name))).toEqual(order);
  expect(seen.some((name) => name.includes('Leave this field empty'))).toBe(false);

  await page.getByText('Transcript: Opening talk').focus();
  await page.keyboard.press('Enter');
  await expect(page.getByText('Good evening, and welcome.')).toBeVisible();
});

test('the contact form explains its errors, keeps what was typed and says when the message was sent', async ({ page }) => {
  await page.goto(`${SITE}/visit`);

  await page.getByLabel('Your message').fill('Do you have large-print books?');
  await page.getByLabel('Your email address').fill('not an address');
  await page.getByRole('button', { name: 'Send message' }).press('Enter');

  const summary = page.getByRole('region', { name: 'There is a problem' });
  await expect(summary).toBeFocused();
  await expect(summary.getByRole('link')).toHaveText([/your name/, /email address/]);
  await expect(page.getByLabel('Your message')).toHaveValue('Do you have large-print books?');
  await expect(page.getByLabel('Your email address')).toHaveAttribute('aria-invalid', 'true');
  await expectNoViolations(page);

  await summary.getByRole('link').first().press('Enter');
  await expect(page.getByLabel('Your name')).toBeFocused();
  await page.keyboard.type('Ada');
  await page.getByLabel('Your email address').fill('ada@example.org');
  await page.getByRole('button', { name: 'Send message' }).press('Enter');

  const status = page.getByRole('status').filter({ hasText: 'Thank you. Your message has been sent.' });
  await expect(status).toBeFocused();
  await expectNoViolations(page);
});

test('the publish report lists errors and suggestions as links to the fields, and gets focus', async ({ page }) => {
  await page.goto(`${SITE}/report`);

  const report = page.getByRole('region', { name: '2 problems stop this page being published' });
  await expect(report).toBeFocused();
  await expect(report.getByRole('heading', { level: 3 })).toHaveText(['Fix before publishing', 'Suggestions']);
  const alt = report.getByRole('link', { name: 'Error: Describe the image, or mark it as decorative.' });
  await expect(alt).toHaveAttribute('href', '#en-gb-blocks-1-data-alt');
  await expect(report.getByRole('link', { name: /^Suggestion: Add a description for search engines/ })).toHaveAttribute('href', '#en-gb-description');
  await expect(report.getByText('(English (United Kingdom), WCAG 1.1.1)')).toBeVisible();
  await expect(report.getByRole('listitem')).toHaveCount(4);
  await expectNoViolations(page);

  await alt.focus();
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(/#en-gb-blocks-1-data-alt$/);
});
