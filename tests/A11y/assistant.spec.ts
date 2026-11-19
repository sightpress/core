// The AI assistant (A-3) on the admin test site's `assistant` copy, with the pretend "Test AI" service (nothing leaves
// the server): an admin chooses the service, enters a key and tests the connection by keyboard; an editor asks for a
// summary with the Suggest button, inserts it and focus returns to the field; a question on the dashboard gets an
// answer under its own heading, which only the polite status region announces; errors go to the alert region. No
// axe violations in light and dark mode, in forced colours or at 320 px; the same flows work without JavaScript.
import { expect, test, type Page } from '@playwright/test';
import { ADMIN_SITES, expectNoViolations, signIn } from './support/admin-site';

const SITE = ADMIN_SITES.assistant;

test.beforeEach(async ({ request }) => {
  await request.get(`${SITE}/_test/reset`);
  expect((await request.get(`${SITE}/login`)).status()).toBe(200);
});

async function ready(page: Page): Promise<void> {
  expect((await page.request.get(`${SITE}/_test/assistant`)).status()).toBe(204);
}

async function volunteerPage(page: Page): Promise<string> {
  await page.goto(`${SITE}/admin/pages`);
  const href = await page.getByRole('link', { name: 'Volunteer with us' }).getAttribute('href');
  expect(href).toMatch(/^\/admin\/pages\/\d+$/);
  return href ?? '';
}

/** The suggestion is never inside a live region: only the short status words are. */
async function expectNotLive(page: Page, text: string): Promise<void> {
  const live = await page.locator('[role="status"], [role="alert"], [aria-live]').allTextContents();
  expect(live.join(' ')).not.toContain(text);
}

test('an admin chooses the service, enters a key and tests the connection by keyboard', async ({ page }) => {
  await signIn(page, SITE, 'ada@example.org', '/admin/assistant');
  await expect(page).toHaveTitle('AI assistant - Admin - Riverside Library');
  await expect(page.getByText('Not ready yet. No AI service is chosen yet.')).toBeVisible();
  await expectNoViolations(page);

  await page.getByRole('button', { name: 'Save the AI service' }).press('Enter');
  const summary = page.getByRole('region', { name: 'There is a problem' });
  await expect(summary).toBeFocused();
  await expect(summary.getByRole('link', { name: 'Choose an AI service.' })).toBeVisible();
  await expectNoViolations(page);

  const services = page.getByRole('group', { name: 'AI service' });
  await services.getByRole('radio', { name: 'Test AI' }).check();
  await expect(services.getByRole('radio', { name: 'Test AI' })).toHaveAccessibleDescription('A pretend AI service for tests. Nothing leaves the server.');
  await page.getByLabel('Model', { exact: true }).selectOption({ label: 'test-small' });
  await page.getByLabel('New API key (optional)').fill('sk-test-a11y-9z8y');
  await page.getByRole('button', { name: 'Save the AI service' }).press('Enter');
  await expect(page.getByRole('status').first()).toHaveText('The AI service is saved.');
  await expect(page.getByText('Ready: people can ask Test AI (test-small).')).toBeVisible();
  await expect(page.getByText('Saved API key: ••••9z8y')).toBeVisible();
  await expect(page.getByLabel('New API key (optional)')).toHaveValue('');

  await page.getByRole('button', { name: 'Test connection' }).press('Enter');
  await expect(page.locator('#assistant-test-result')).toHaveText('The connection works: Test AI answered.');

  await page.getByRole('group', { name: 'Who may use the assistant (optional)' }).getByRole('checkbox', { name: 'Contributor' }).uncheck();
  await page.getByLabel('Monthly budget in tokens (optional)').fill('100000');
  await page.getByRole('button', { name: 'Save who may use it' }).press('Enter');
  await expect(page.getByRole('status').first()).toHaveText('Who may use the assistant and its budget are saved.');
  await expect(page.getByRole('heading', { name: 'Who may use it, and how much' })).toBeFocused();
  await expect(page.getByText(/Used this month: \d+ of 100,000 tokens/)).toBeVisible();

  await page.getByRole('button', { name: 'Remove key' }).press('Enter');
  await expect(page.getByRole('status').first()).toHaveText('The API key is removed.');
  await expect(page.getByText('This site has no API key of its own.')).toBeVisible();
});

test('an editor asks for a summary, inserts it, and focus returns to the field', async ({ page }) => {
  await ready(page);
  await signIn(page, SITE, 'bea@example.org', '/admin');
  const path = await volunteerPage(page);
  await page.goto(`${SITE}${path}`);
  const suggest = page.getByRole('button', { name: 'Suggest a summary' });
  await suggest.focus();
  await page.keyboard.press('Enter');

  const panel = page.getByRole('complementary', { name: 'Ask the assistant' });
  const heading = panel.getByRole('heading', { name: 'Suggestion for “Summary”' });
  await expect(heading).toBeFocused();
  await expect(panel.getByRole('status')).toHaveText('Suggestion ready');
  await expect(panel.getByText('When the library is open, and the days it is closed.')).toBeVisible();
  await expectNotLive(page, 'When the library is open');
  await expectNoViolations(page);

  await page.keyboard.press('Tab');
  await expect(panel.getByRole('button', { name: 'Insert' })).toBeFocused();
  await page.keyboard.press('Enter');
  const field = page.getByLabel('Summary (optional)');
  await expect(field).toBeFocused();
  await expect(field).toHaveValue('When the library is open, and the days it is closed.');
  await expect(panel.getByRole('status')).toHaveText('Inserted. Check it, then save.');
  await expect(heading).toHaveCount(0);

  await page.getByRole('button', { name: 'Suggest a description' }).press('Enter');
  await expect(panel.getByRole('heading', { name: 'Suggestion for “Description for search engines”' })).toBeFocused();
  await panel.getByRole('button', { name: 'Discard' }).press('Enter');
  await expect(page.getByRole('button', { name: 'Suggest a description' })).toBeFocused();
  await expect(panel.getByRole('status')).toHaveText('Suggestion discarded.');
});

test('a question on the dashboard gets an answer, and errors are announced', async ({ page }) => {
  await ready(page);
  await signIn(page, SITE, 'cleo@example.org', '/admin');
  const panel = page.getByRole('complementary', { name: 'Ask the assistant' });
  await expectNoViolations(page);

  await panel.getByLabel('Your question').fill('How do I add a page to the main menu?');
  await panel.getByRole('button', { name: 'Ask' }).press('Enter');
  await expect(panel.getByRole('heading', { name: 'The assistant’s answer' })).toBeFocused();
  await expect(panel.getByText('Open Menus in the admin panel, choose the main menu, then add the page to it.')).toBeVisible();
  await expect(panel.getByRole('button', { name: 'Insert' })).toHaveCount(0);
  await expectNotLive(page, 'Open Menus');
  await expectNoViolations(page);

  await panel.getByLabel('Your question').fill('Help [[busy]]');
  await panel.getByRole('button', { name: 'Ask' }).press('Enter');
  await expect(panel.getByRole('alert')).toHaveText('The AI service is getting too many requests right now. Try again in a minute.');
  await expect(panel.getByRole('heading', { name: 'The assistant’s answer' })).toHaveCount(0);
});

test('a picture gets alt text from the assistant, which stays marked until someone checks it', async ({ page }) => {
  await ready(page);
  const id = await (await page.request.get(`${SITE}/_test/picture`)).text();
  await signIn(page, SITE, 'bea@example.org', `/admin/media/${id}`);
  await page.waitForLoadState('load');
  await page.getByRole('button', { name: 'Suggest alt text' }).press('Enter');
  const panel = page.getByRole('complementary', { name: 'Ask the assistant' });
  await expect(panel.getByRole('heading', { name: 'Suggestion for “Alt text”' })).toBeFocused();
  await panel.getByRole('button', { name: 'Insert' }).press('Enter');
  const alt = page.getByLabel('Alt text (optional)');
  await expect(alt).toBeFocused();
  await expect(alt).toHaveValue('A reading room with tall windows and children reading on cushions.');
  await page.getByRole('button', { name: 'Save details' }).press('Enter');
  await expect(page.getByText('The AI assistant suggested this alt text.')).toBeVisible();
  await expectNoViolations(page);

  await page.getByRole('checkbox', { name: 'I checked the alt text: it describes the picture' }).check();
  await page.getByRole('button', { name: 'Save details' }).press('Enter');
  await expect(page.getByText('The AI assistant suggested this alt text.')).toHaveCount(0);
});

test('an editor drafts a page, adds the draft, and asks about a publish check', async ({ page }) => {
  await ready(page);
  await signIn(page, SITE, 'bea@example.org', '/admin');
  const path = await volunteerPage(page);
  await page.goto(`${SITE}${path}`);
  const panel = page.getByRole('complementary', { name: 'Ask the assistant' });
  await panel.getByLabel('What should the page say?').fill('A page about our book club.');
  await panel.getByRole('button', { name: 'Draft this page' }).press('Enter');
  await expect(panel.getByRole('heading', { name: 'Draft of the page' })).toBeFocused();
  await expectNotLive(page, 'Join our book club');
  await panel.getByRole('button', { name: 'Add to the end of the page' }).press('Enter');
  await expect(page.getByRole('status').first()).toHaveText('The draft is at the end of the page’s blocks. Check it, then save.');
  await expect(page.locator('input[value="Join our book club"]')).toHaveCount(1);

  await page.getByRole('button', { name: /^Explain this check: / }).first().press('Enter');
  await expect(panel.getByRole('heading', { name: 'About this check' })).toBeFocused();
  await expectNoViolations(page);
});

test('the screens pass axe in forced colours and at 320 px', async ({ page }) => {
  await ready(page);
  await signIn(page, SITE, 'ada@example.org', '/admin/assistant');
  for (const path of ['/admin/assistant', await volunteerPage(page)]) {
    await page.goto(`${SITE}${path}`);
    await page.emulateMedia({ forcedColors: 'active' });
    await expectNoViolations(page);
    await page.emulateMedia({ forcedColors: 'none' });
    await page.setViewportSize({ width: 320, height: 640 });
    await expectNoViolations(page);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
    expect(overflow, path).toBe(false);
    await page.setViewportSize({ width: 1280, height: 720 });
  }
});

test.describe('without JavaScript', () => {
  test.use({ javaScriptEnabled: false });

  test('Suggest goes to a page with the suggestion, and Insert puts it in the field', async ({ page }) => {
    await ready(page);
    await signIn(page, SITE, 'bea@example.org', '/admin');
    const path = await volunteerPage(page);
    await page.goto(`${SITE}${path}`);
    await page.getByRole('button', { name: 'Suggest a summary' }).click();
    await expect(page).toHaveTitle('Suggestion for “Summary” - Admin - Riverside Library');
    await expect(page.getByText('When the library is open, and the days it is closed.')).toBeVisible();
    await page.getByRole('button', { name: 'Insert' }).click();
    await expect(page).toHaveURL(`${SITE}${path}#en-gb-summary`);
    await expect(page.getByRole('status').first()).toHaveText('The suggestion is in “Summary”. Check it, then save.');
    await expect(page.getByLabel('Summary (optional)')).toHaveValue('When the library is open, and the days it is closed.');
  });

  test('a question goes to a page with the answer, and Discard goes back', async ({ page }) => {
    await ready(page);
    await signIn(page, SITE, 'ada@example.org', '/admin');
    await page.getByLabel('Your question').fill('How do I add a page to the main menu?');
    await page.getByRole('button', { name: 'Ask' }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'The assistant’s answer' })).toBeVisible();
    await expect(page.getByText('Open Menus in the admin panel, choose the main menu, then add the page to it.')).toBeVisible();
    await page.getByRole('link', { name: 'Discard and go back' }).click();
    await expect(page).toHaveURL(`${SITE}/admin`);
  });
});
