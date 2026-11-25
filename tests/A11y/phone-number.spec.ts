// Mobile numbers (N-3) on the `phone` copy of the admin test site, which has text messages on with a pretend
// provider. After signing in without a number, Max is asked to add one: a country select and a mobile number field,
// then the 6-digit code that came by text (no link), all by keyboard. A wrong number is refused in the focused error
// summary. On My account Max chooses the text messages to get. "Skip for now" goes on to where Max was going.
// No axe violations in light and dark mode.
import { expect, test, type Page } from '@playwright/test';
import { ADMIN_SITES, PASSWORD, expectNoViolations } from './support/admin-site';

const SITE = ADMIN_SITES.phone;

test.beforeEach(async ({ request }) => {
  await request.get(`${SITE}/_test/reset`);
});

async function signInAs(page: Page, email: string, path: string): Promise<void> {
  await page.goto(`${SITE}/login?return=${encodeURIComponent(path)}`);
  await page.getByLabel('Email address').fill(email);
  await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
  await page.getByRole('button', { name: 'Sign in' }).click();
}

test('a member adds a mobile number by keyboard after signing in', async ({ page, request }) => {
  await signInAs(page, 'max@example.org', '/account');
  await expect(page).toHaveURL(`${SITE}/account/phone`);
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Add your mobile number');
  await expect(page.getByLabel('Country')).toHaveValue('GB');
  await expect(page.getByLabel('Mobile number')).toHaveAttribute('type', 'tel');
  await expect(page.getByLabel('Mobile number')).toHaveAttribute('autocomplete', 'tel-national');
  await expectNoViolations(page);

  await page.getByLabel('Mobile number').fill('020 7946 0000');
  await page.getByLabel('Mobile number').press('Enter');
  await expect(page.locator('#phone-errors')).toBeFocused();
  await expect(page.locator('#phone-errors')).toContainText('This isn’t a mobile number, so it can’t get text messages.');
  await expect(page.getByLabel('Mobile number')).toHaveAttribute('aria-invalid', 'true');
  await expectNoViolations(page);

  await page.getByLabel('Mobile number').fill('07400 123456');
  await page.getByLabel('Mobile number').press('Enter');
  await expect(page).toHaveTitle('Confirm your mobile number - Riverside Library');
  await expect(page.getByText('We sent it by text message to •••• •••• 3456.')).toBeVisible();
  await expect(page.getByLabel('6-digit code')).toHaveAttribute('autocomplete', 'one-time-code');
  await expectNoViolations(page);

  const text = await (await request.get(`${SITE}/_test/texts?to=%2B447400123456`)).json() as { text: string };
  expect(text.text).not.toMatch(/https?:|www\./);
  const code = /^(\d{3}) (\d{3}) is your code for Riverside Library\./.exec(text.text);
  expect(code).not.toBeNull();
  await page.getByLabel('6-digit code').fill(`${code?.[1]} ${code?.[2]}`);
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(`${SITE}/account`);
  await expect(page.getByRole('status')).toHaveText('Your mobile number is now 07400 123456.');
  await expect(page.getByText('Mobile number: 07400 123456')).toBeVisible();
  await expectNoViolations(page);

  await page.getByRole('link', { name: 'Change your number or your text messages' }).click();
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Your mobile number');
  const texts = page.getByLabel('Send me text messages');
  await expect(texts).not.toBeChecked();
  await texts.focus();
  await page.keyboard.press('Space');
  await page.getByLabel('When a page is sent to you for review').check();
  await page.getByRole('button', { name: 'Save my choices' }).press('Enter');
  await expect(page.getByRole('status')).toHaveText('You will get the text messages you chose.');
  await expect(page.getByLabel('Send me text messages')).toBeChecked();
  await expectNoViolations(page);
});

test('the step can be skipped', async ({ page }) => {
  await signInAs(page, 'bea@example.org', '/admin');
  await expect(page).toHaveURL(`${SITE}/account/phone`);
  await page.getByRole('button', { name: 'Skip for now' }).focus();
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(`${SITE}/admin`);
});

test('an admin checks the text messages screen and sends a test by keyboard', async ({ page, request }) => {
  await signInAs(page, 'ada@example.org', '/admin/sms');
  await page.getByRole('button', { name: 'Skip for now' }).click();
  await expect(page).toHaveURL(`${SITE}/admin/sms`);
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Text messages');
  await expect(page.getByText('Ready: messages are sent with Test SMS.')).toBeVisible();
  await expect(page.getByText('No text messages have been sent yet.')).toBeVisible();
  await expectNoViolations(page);

  await page.getByLabel('Mobile number').fill('nonsense');
  await page.getByLabel('Mobile number').press('Enter');
  await expect(page.locator('#sms-test-errors')).toBeFocused();
  await expect(page.getByLabel('Mobile number')).toHaveAttribute('aria-invalid', 'true');
  await expectNoViolations(page);

  await page.getByLabel('Mobile number').fill('07400 123456');
  await page.getByLabel('Mobile number').press('Enter');
  await expect(page.getByRole('status')).toHaveText('The test message is on its way to +44•••••••456.');
  const table = page.getByRole('table', { name: 'The latest messages, newest first. Numbers are partly hidden and the text is never kept.' });
  await expect(table.getByRole('cell', { name: '+44•••••••456' })).toBeVisible();
  await expectNoViolations(page);
  const text = await (await request.get(`${SITE}/_test/texts?to=%2B447400123456`)).json() as { text: string };
  expect(text.text).toBe('Test message from Riverside Library: text messages work.');
});
