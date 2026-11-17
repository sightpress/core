// The page screens (C-7.2) on the admin test site: the page tree with its disclosure buttons and filters, starting
// a page, and the page editor. Blocks are added, moved and removed by keyboard with every change announced and focus
// kept in place; errors go to the focused error summary; the description's character count is a polite live region;
// the workflow buttons take the page to review. No axe violations in light and dark mode; reflow at 320px.
import { expect, test, type Page } from '@playwright/test';
import { ADMIN_SITES, expectNoViolations, signIn } from './support/admin-site';

const SITE = ADMIN_SITES.pages;

test.beforeEach(async ({ request }) => {
  await request.get(`${SITE}/_test/reset`);
});

async function startPage(page: Page, title: string, parent?: string): Promise<void> {
  await page.goto(`${SITE}/admin/pages/new`);
  await page.getByLabel('Title', { exact: true }).fill(title);
  if (parent) {
    await page.getByLabel('Put it under').selectOption({ label: parent });
  }
  await page.getByRole('button', { name: 'Start the page' }).click();
  await expect(page.getByRole('heading', { level: 1, name: `Edit “${title}”` })).toBeVisible();
}

test('the page list is a tree whose subpages open with a button, and filters find pages', async ({ page }) => {
  await signIn(page, SITE, 'bea@example.org', '/admin/pages');
  await startPage(page, 'Our team', 'Opening hours');
  await page.getByRole('navigation', { name: 'Admin' }).getByRole('link', { name: 'Pages' }).click();

  await expect(page).toHaveTitle('Pages - Admin - Riverside Library');
  const toggle = page.getByRole('button', { name: 'Show the pages under Opening hours' });
  await expect(toggle).toHaveAttribute('aria-expanded', 'false');
  await expect(page.getByRole('link', { name: 'Our team' })).toBeHidden();
  await expectNoViolations(page);

  await toggle.focus();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('button', { name: 'Hide the pages under Opening hours' })).toHaveAttribute('aria-expanded', 'true');
  await expect(page.getByRole('link', { name: 'Our team' })).toBeVisible();
  await expectNoViolations(page);

  await page.getByLabel('State').selectOption('in_review');
  await page.getByRole('button', { name: 'Show pages' }).click();
  await expect(page.getByRole('heading', { level: 2, name: '1 page matches' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Summer reading challenge' })).toBeVisible();
  await expectNoViolations(page);
});

test('blocks are added, moved and removed by keyboard, with each change announced and focus kept', async ({ page }) => {
  await signIn(page, SITE, 'bea@example.org', '/admin/pages');
  await page.getByRole('link', { name: 'Volunteer with us' }).click();
  await expect(page).toHaveTitle('Edit “Volunteer with us” - Admin - Riverside Library');
  await expectNoViolations(page);

  const status = page.getByRole('status');
  await page.getByLabel('Type of block to add').selectOption({ label: 'Heading' });
  await page.getByRole('button', { name: 'Add block' }).focus();
  await page.keyboard.press('Enter');
  await expect(status).toHaveText('Added a Heading block. It is block 1 of 1. Save the page to keep this change.');
  await expect(page.getByRole('group', { name: 'Block 1 of 1: Heading' })).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(page.getByLabel('Heading text')).toBeFocused();
  await page.keyboard.type('How to help');

  await page.getByLabel('Type of block to add').selectOption({ label: 'Text' });
  await page.getByRole('button', { name: 'Add block' }).click();
  await expect(page.getByRole('group', { name: 'Block 2 of 2: Text' })).toBeFocused();
  await expect(page.getByLabel('Heading text')).toHaveValue('How to help');
  await page.getByLabel('Text', { exact: true }).fill('<p>Come and <strong>read</strong> with us.</p>');
  await expectNoViolations(page);

  await page.getByRole('button', { name: 'Move up: block 2, Text' }).focus();
  await page.keyboard.press('Enter');
  await expect(status).toHaveText('Moved block 2 (Text) up. It is now block 1 of 2. Save the page to keep this change.');
  await expect(page.getByRole('button', { name: 'Move down: block 1, Text' })).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('button', { name: 'Move up: block 2, Text' })).toBeFocused();

  await page.getByRole('button', { name: 'Update the previews' }).click();
  await page.getByText('Preview of block 2, Text').click();
  await expect(page.locator('.block-editor__preview-content strong')).toHaveText('read');
  await expectNoViolations(page);

  await page.getByRole('button', { name: 'Remove: block 1, Heading' }).click();
  await expect(status).toHaveText('Removed block 1 (Heading). Save the page to keep this change.');
  await expect(page.locator('#en-gb-blocks')).toBeFocused();
});

test('a page that can’t be saved explains why in the focused error summary, then saves', async ({ page }) => {
  await signIn(page, SITE, 'bea@example.org', '/admin/pages');
  await page.getByRole('link', { name: 'Volunteer with us' }).click();
  await page.getByLabel('Type of block to add').selectOption({ label: 'Heading' });
  await page.getByRole('button', { name: 'Add block' }).click();
  await page.getByRole('button', { name: 'Save page' }).first().click();

  await expect(page).toHaveTitle('1 error: Edit “Volunteer with us” - Admin - Riverside Library');
  await expect(page.locator('#page-editor-errors')).toBeFocused();
  await expect(page.locator('#page-editor-errors').getByRole('link')).toHaveText(['Heading text: Fill in this field.']);
  await expectNoViolations(page);
  await page.keyboard.press('Tab');
  await expect(page.getByRole('link', { name: 'Heading text: Fill in this field.' })).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(page.getByLabel('Heading text')).toBeFocused();

  await page.keyboard.type('Ways to help');
  await page.keyboard.press('Enter');
  await expect(page.getByRole('status')).toHaveText('Saved “Volunteer with us”.');
  await expect(page.getByLabel('Heading text')).toHaveValue('Ways to help');
});

test('the description has a live character count and a search result preview', async ({ page }) => {
  await signIn(page, SITE, 'bea@example.org', '/admin/pages');
  await page.getByRole('link', { name: 'Volunteer with us' }).click();

  const count = page.locator('#en-gb-description-count');
  await expect(count).toHaveAttribute('aria-live', 'polite');
  await expect(count).toHaveText('0 characters. Search engines show about 160.');
  await page.getByLabel('Description for search engines').fill('Help us run reading groups.');
  await expect(count).toHaveText('27 characters. Search engines show about 160.');
  await page.getByRole('button', { name: 'Update the previews' }).click();
  await expect(page.locator('#search-preview')).toBeFocused();
  await expect(page.locator('.search-preview__description')).toHaveText('Help us run reading groups.');
  await expectNoViolations(page);
});

test('a contributor sends a page for review and then sees it read-only', async ({ page }) => {
  await signIn(page, SITE, 'cleo@example.org', '/admin/pages');
  await page.getByRole('link', { name: 'Opening hours' }).click();
  await page.getByLabel('Note for the next person').fill('Ready for a look');
  await page.getByRole('button', { name: 'Submit for review' }).click();

  await expect(page.getByRole('status')).toHaveText('Sent for review. The editors have been told by email.');
  await expect(page.getByText('You can’t change this page now: it is waiting for review.')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Save page' })).toHaveCount(0);
  await expectNoViolations(page);
});

test('the page screens reflow at 320px', async ({ page }) => {
  await page.setViewportSize({ width: 320, height: 640 });
  await signIn(page, SITE, 'bea@example.org', '/admin/pages');
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
  await page.getByRole('link', { name: 'Volunteer with us' }).click();
  await page.getByLabel('Type of block to add').selectOption({ label: 'Columns' });
  await page.getByRole('button', { name: 'Add block' }).click();
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
  await expectNoViolations(page);
});
