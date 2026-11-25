// Phone sign-in (N-5) on the `phonesignin` copy of the admin test site, which has text messages on with a pretend
// provider. The sign-in field says it takes an email address or a mobile number; Max signs in with his confirmed
// number by keyboard; a wrong number gets the same error as a wrong address; Max resets his password with a code by
// text (no link) and gets an email about the change. No axe violations in light and dark mode.
import { expect, test } from '@playwright/test';
import { ADMIN_SITES, PASSWORD, expectNoViolations } from './support/admin-site';

const SITE = ADMIN_SITES.phonesignin;

test.beforeEach(async ({ request }) => {
  await request.get(`${SITE}/_test/reset`);
  await request.get(`${SITE}/login`);
  await request.get(`${SITE}/_test/phone?email=max@example.org&phone=%2B447400123456`);
});

test('a member signs in with their mobile number by keyboard', async ({ page }) => {
  await page.goto(`${SITE}/login`);
  const field = page.getByLabel('Email address or mobile number');
  await expect(field).toHaveAttribute('type', 'text');
  await expect(field).toHaveAttribute('autocomplete', 'username');
  await expect(page.getByText('Mobile numbers can start with + and the country code.')).toBeVisible();
  await expectNoViolations(page);

  await field.fill('07400 999999');
  await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
  await page.keyboard.press('Enter');
  await expect(page.locator('#sign-in-errors')).toBeFocused();
  await expect(page.locator('#sign-in-errors')).toContainText('Enter a correct email address or mobile number, and password.');
  await expectNoViolations(page);

  await page.getByLabel('Email address or mobile number').fill('07400 123456');
  await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(`${SITE}/`);
});

test('a member resets their password with a code by text', async ({ page, request }) => {
  await page.goto(`${SITE}/login/forgot`);
  await expect(page.getByText('People who manage the site reset their password by email only, to keep it safe.')).toBeVisible();
  await expectNoViolations(page);
  await page.getByLabel('Email address or mobile number').fill('+44 7400 123456');
  await page.keyboard.press('Enter');
  await expect(page).toHaveTitle('Enter your code - Riverside Library');
  await expect(page.getByText('We sent it by text message to •••• •••• 3456.')).toBeVisible();
  await expectNoViolations(page);

  const text = await (await request.get(`${SITE}/_test/texts?to=%2B447400123456`)).json() as { text: string };
  expect(text.text).not.toMatch(/https?:|www\./);
  const code = /^(\d{3}) (\d{3}) is your code to reset your password/.exec(text.text);
  expect(code).not.toBeNull();
  await page.getByLabel('6-digit code').fill(`${code?.[1]}${code?.[2]}`);
  await page.keyboard.press('Enter');
  await page.getByLabel('New password').fill('A brand new long password 9!');
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(`${SITE}/login`);

  const email = await (await request.get(`${SITE}/_test/mail?to=max@example.org`)).json() as { subject: string; text: string };
  expect(email.subject).toBe('The password of your account on Riverside Library was changed');
  expect(email.text).toContain('after a code we sent to your mobile number');
});
