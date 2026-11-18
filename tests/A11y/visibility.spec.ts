// Members-only visibility (U-2) on the admin test site: an admin makes the whole site members-only by keyboard on Site
// settings; a visitor is then taken to sign in (which says why) and brought back to the page they asked for; an
// editor marks one page "Members only" by keyboard in the editor. No axe violations in light and dark mode.
import { expect, test } from '@playwright/test';
import { ADMIN_SITES, PASSWORD, expectNoViolations, signIn } from './support/admin-site';

const SITE = ADMIN_SITES.visibility;

test.beforeEach(async ({ request }) => {
  await request.get(`${SITE}/_test/reset`);
});

test('an admin makes the site members-only, and visitors sign in and come back', async ({ page, browser }) => {
  await signIn(page, SITE, 'ada@example.org', '/admin/settings');
  const who = page.getByRole('group', { name: 'Who can read the site' });
  await expect(who.getByRole('radio', { name: /^Everyone/ })).toBeChecked();
  await expectNoViolations(page);

  await who.getByRole('radio', { name: /^Everyone/ }).focus();
  await page.keyboard.press('ArrowDown');
  await expect(who.getByRole('radio', { name: /^Only people with an account/ })).toBeChecked();
  await page.getByRole('button', { name: 'Save settings' }).focus();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('status')).toHaveText('The settings have been saved.');
  await expectNoViolations(page);

  const context = await browser.newContext();
  const visitor = await context.newPage();
  const robots = await (await visitor.request.get(`${SITE}/robots.txt`)).text();
  expect(robots).toContain('User-agent: *\nDisallow: /\n');
  await visitor.goto(`${SITE}/account`);
  await expect(visitor).toHaveURL(`${SITE}/login?return=%2Faccount`);
  await expect(visitor.getByText('This site is for its members. Sign in to read it.')).toBeVisible();
  await expectNoViolations(visitor);
  await visitor.getByLabel('Email address').fill('max@example.org');
  await visitor.getByLabel('Password', { exact: true }).fill(PASSWORD);
  await visitor.keyboard.press('Enter');
  await expect(visitor).toHaveURL(`${SITE}/account`);
  await context.close();
});

test('an editor makes a page members-only by keyboard', async ({ page }) => {
  await signIn(page, SITE, 'bea@example.org', '/admin/pages');
  await page.getByRole('link', { name: 'Volunteer with us' }).click();
  const who = page.getByRole('group', { name: 'Who can read this page' });
  await expect(who.getByRole('radio', { name: 'Everyone' })).toBeChecked();
  await expect(page.getByText('People who aren’t signed in are asked to sign in.')).toBeVisible();

  await who.getByRole('radio', { name: 'Everyone' }).focus();
  await page.keyboard.press('ArrowDown');
  await page.getByRole('button', { name: 'Save page', exact: true }).first().focus();
  await page.keyboard.press('Enter');

  await expect(page.getByRole('status')).toHaveText('Saved “Volunteer with us”.');
  await expect(page.getByRole('group', { name: 'Who can read this page' }).getByRole('radio', { name: /^Members only/ })).toBeChecked();
  await expectNoViolations(page);
});
