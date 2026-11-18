// Profile on My account (U-3) on the admin test site: a member changes their password and their email address by
// keyboard (the address only after the emailed code; neither email has a link), mistakes are explained in the
// focused error summary, and someone who uses the admin chooses less motion and larger text, which the admin panel
// then follows without any script. No axe violations in light and dark mode.
import { expect, test } from '@playwright/test';
import { ADMIN_SITES, PASSWORD, expectNoViolations, signIn } from './support/admin-site';

const SITE = ADMIN_SITES.profile;
const NEW_PASSWORD = 'A brand new password 9?';

type Mail = { subject: string; text: string; html: string };

test.beforeEach(async ({ request }) => {
  await request.get(`${SITE}/_test/reset`);
});

test('a member changes their password by keyboard and a wrong current password is explained', async ({ page }) => {
  await signIn(page, SITE, 'max@example.org', '/account');
  await page.getByRole('link', { name: 'Change your password' }).focus();
  await page.keyboard.press('Enter');
  await expect(page).toHaveTitle('Change your password - Riverside Library');
  await expect(page.getByLabel('New password')).toHaveAccessibleDescription(/^At least 12 characters/);
  await expectNoViolations(page);

  await page.getByLabel('Current password').fill('Not my password 1!');
  await page.keyboard.press('Tab');
  await page.keyboard.type(NEW_PASSWORD);
  await page.keyboard.press('Enter');
  await expect(page).toHaveTitle('Error: Change your password - Riverside Library');
  await expect(page.locator('#change-password-errors')).toBeFocused();
  await expect(page.getByLabel('Current password')).toHaveAttribute('aria-invalid', 'true');
  await expectNoViolations(page);

  await page.getByLabel('Current password').fill(PASSWORD);
  await page.getByLabel('New password').fill(NEW_PASSWORD);
  await page.getByRole('button', { name: 'Change password' }).focus();
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(`${SITE}/account`);
  await expect(page.getByRole('status')).toHaveText('Your password has been changed. You are still signed in here, and signed out everywhere else.');
  await expectNoViolations(page);
});

test('a member moves to a new email address with the emailed code, and the old address is told', async ({ page, request }) => {
  await signIn(page, SITE, 'max@example.org', '/account');
  await page.getByRole('link', { name: 'Change your email address' }).click();
  await expect(page).toHaveTitle('Change your email address - Riverside Library');
  await expect(page.getByText('You sign in with max@example.org now.')).toBeVisible();
  await expectNoViolations(page);

  await page.getByLabel('New email address').fill('max@example.org');
  await page.getByLabel('Current password').fill(PASSWORD);
  await page.keyboard.press('Enter');
  await expect(page.locator('#change-email-errors')).toBeFocused();
  await expect(page.locator('#change-email-errors')).toContainText('That is already your email address. Enter the new one.');

  await page.getByLabel('New email address').fill('max@new.example');
  await page.getByLabel('Current password').fill(PASSWORD);
  await page.getByRole('button', { name: 'Send the code' }).click();
  await expect(page).toHaveTitle('Confirm your new email address - Riverside Library');
  await expect(page.getByText('We sent it by email to m•••@new.example.')).toBeVisible();
  await expectNoViolations(page);

  const sent = await (await request.get(`${SITE}/_test/mail?to=max@new.example`)).json() as Mail;
  expect(sent.subject).toBe('Your code to confirm your new email address for Riverside Library');
  const code = /^(\d{3}) (\d{3})$/m.exec(sent.text);
  expect(code).not.toBeNull();

  await page.getByLabel('6-digit code').fill(`${code?.[1]}${code?.[2]}`);
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(`${SITE}/account`);
  await expect(page.getByRole('status')).toHaveText('Your email address is now max@new.example. Sign in with it from now on.');
  await expect(page.getByText('Email address: max@new.example')).toBeVisible();
  await expectNoViolations(page);

  const notice = await (await request.get(`${SITE}/_test/mail?to=max@example.org`)).json() as Mail;
  expect(notice.subject).toBe('The email address of your account on Riverside Library has changed');
  for (const body of [sent.text, sent.html, notice.text, notice.html]) {
    expect(body).not.toMatch(/https?:\/\/|<a\b|www\.|127\.0\.0\.1|<img\b/i);
  }
});

test('admin preferences make the admin panel still and its text larger, with no script', async ({ page, browser }) => {
  const member = await browser.newPage();
  await signIn(member, SITE, 'max@example.org', '/account');
  await expect(member.getByRole('heading', { name: 'Admin preferences' })).toHaveCount(0);
  await member.close();

  await signIn(page, SITE, 'ada@example.org', '/account');
  await expect(page.getByRole('heading', { level: 2, name: 'Admin preferences' })).toBeVisible();
  await page.getByLabel('Reduce motion (optional)').focus();
  await page.keyboard.press('Space');
  await page.keyboard.press('Tab');
  await expect(page.getByLabel('Larger text (optional)')).toBeFocused();
  await page.keyboard.press('Space');
  await page.getByRole('button', { name: 'Save preferences' }).focus();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('status')).toHaveText('Your admin preferences have been saved.');
  await expect(page.getByLabel('Reduce motion (optional)')).toBeChecked();
  await expectNoViolations(page);

  await page.goto(`${SITE}/admin`);
  await expect(page.locator('html')).toHaveClass('admin-reduced-motion admin-larger-text');
  const size = await page.evaluate(() => getComputedStyle(document.documentElement).fontSize);
  expect(size).toBe('20px');
  await expectNoViolations(page);
});
