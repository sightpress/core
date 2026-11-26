// Two-step confirmation (N-6) on the `twostep` copy of the admin test site, which has text messages on with a pretend
// provider. Ada turns it on in Site settings → Security by keyboard: a code goes to her number, she types it and makes
// the change. Then Max's change of email address waits on "Confirm it's you" until he types a code, and comes back
// ready to send again, without his password. No axe violations in light and dark mode.
import { expect, test, type Page } from '@playwright/test';
import { ADMIN_SITES, PASSWORD, expectNoViolations } from './support/admin-site';

const SITE = ADMIN_SITES.twostep;

test.beforeEach(async ({ request }) => {
  await request.get(`${SITE}/_test/reset`);
  await request.get(`${SITE}/login`);
  await request.get(`${SITE}/_test/phone?email=ada@example.org&phone=%2B447400123450`);
  await request.get(`${SITE}/_test/phone?email=max@example.org&phone=%2B447400123456`);
});

async function signInAs(page: Page, email: string, path: string): Promise<void> {
  await page.goto(`${SITE}/login?return=${encodeURIComponent(path)}`);
  await page.getByLabel('Email address or mobile number').fill(email);
  await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page).toHaveURL(`${SITE}${path}`);
}

async function typeTheCode(page: Page, request: import('@playwright/test').APIRequestContext, to: string): Promise<void> {
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Confirm it’s you');
  await expectNoViolations(page);
  await page.getByRole('button', { name: 'Send me a code' }).press('Enter');
  await expect(page.getByLabel('6-digit code')).toBeVisible();
  await expectNoViolations(page);
  const text = await (await request.get(`${SITE}/_test/texts?to=${encodeURIComponent(to)}`)).json() as { text: string };
  expect(text.text).not.toMatch(/https?:|www\./);
  const code = /^(\d{3}) (\d{3}) is your code to confirm a change/.exec(text.text);
  expect(code).not.toBeNull();
  await page.getByLabel('6-digit code').fill(`${code?.[1]}${code?.[2]}`);
  await page.keyboard.press('Enter');
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('You confirmed it’s you');
  await expectNoViolations(page);
}

test('an admin turns two-step confirmation on by keyboard', async ({ page, request }) => {
  await signInAs(page, 'ada@example.org', '/admin/settings/security');
  await expect(page.getByText('It is off.')).toBeVisible();
  await expectNoViolations(page);
  const on = page.getByRole('radio', { name: 'On: important changes need a code sent by text message' });
  await on.focus();
  await page.keyboard.press('Space');
  await page.getByRole('button', { name: 'Save' }).press('Enter');

  await typeTheCode(page, request, '+447400123450');
  await page.getByRole('button', { name: 'Make the change' }).press('Enter');
  await expect(page).toHaveURL(`${SITE}/admin/settings/security`);
  await expect(page.getByRole('status')).toHaveText('Two-step confirmation is on. Important changes now need a code sent to your mobile number.');
  await expect(page.getByText('It is on.')).toBeVisible();
  await expectNoViolations(page);
});

test('a change of email address waits for the code and comes back without the password', async ({ page, request }) => {
  await request.get(`${SITE}/_test/two-step`);
  await signInAs(page, 'max@example.org', '/account/email');
  await page.getByLabel('New email address').fill('max@new.example');
  await page.getByLabel('Current password').fill(PASSWORD);
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(`${SITE}/confirm`);
  await expect(page.getByText('Nothing has changed yet.')).toBeVisible();

  await typeTheCode(page, request, '+447400123456');
  await expect(page.getByText('We don’t keep passwords or files.')).toBeVisible();
  await page.getByRole('link', { name: 'Go back to the form' }).click();
  await expect(page).toHaveURL(`${SITE}/account/email`);
  await page.getByLabel('New email address').fill('max@new.example');
  await page.getByLabel('Current password').fill(PASSWORD);
  await page.keyboard.press('Enter');
  await expect(page).toHaveTitle('Confirm your new email address - Riverside Library');
});
