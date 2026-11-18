// Self-registration (U-1) on the admin test site: an admin lets anyone sign up on the "Sign-up" screen by keyboard,
// then a visitor finds "Create an account" on the sign-in page, fills in the form (their browser's time zone already
// chosen), types the 6-digit code from the email — which has no link — and signs in as a member. Mistakes are
// explained in the focused error summary. No axe violations in light and dark mode.
import { expect, test } from '@playwright/test';
import { ADMIN_SITES, PASSWORD, expectNoViolations, signIn } from './support/admin-site';

const SITE = ADMIN_SITES.signup;

test.beforeEach(async ({ request }) => {
  await request.get(`${SITE}/_test/reset`);
});

test('there is no sign-up until an admin opens it on the Sign-up screen by keyboard', async ({ page, request }) => {
  expect((await request.get(`${SITE}/register`)).status()).toBe(404);

  await signIn(page, SITE, 'ada@example.org', '/admin/settings');
  await page.getByRole('link', { name: 'Change who can sign up' }).click();
  await expect(page).toHaveTitle('Sign-up - Admin - Riverside Library');
  await expect(page.getByText('Only people you invite can join. Nobody can create an account on their own.')).toBeVisible();
  const role = page.getByLabel('Role of people who sign up');
  await expect(role.locator('option')).toHaveText(['Choose an option', 'Contributor', 'Member']);
  await expectNoViolations(page);

  await page.getByLabel('Let anyone create an account (optional)').focus();
  await page.keyboard.press('Space');
  await page.getByRole('button', { name: 'Save sign-up settings' }).focus();
  await page.keyboard.press('Enter');

  await expect(page.getByRole('status')).toHaveText('The sign-up settings have been saved.');
  await expect(page.getByText('Anyone can create an account. New accounts get the “Member” role once they confirm their email address.')).toBeVisible();
  await expectNoViolations(page);
});

test.describe('a visitor', () => {
  test.use({ timezoneId: 'Asia/Tokyo' });

  test.beforeEach(async ({ browser }) => {
    const admin = await browser.newPage();
    await signIn(admin, SITE, 'ada@example.org', '/admin/settings/sign-up');
    await admin.getByLabel('Let anyone create an account (optional)').check();
    await admin.getByRole('button', { name: 'Save sign-up settings' }).click();
    await expect(admin.getByRole('status')).toHaveText('The sign-up settings have been saved.');
    await admin.close();
  });

  test('signs up by keyboard, types the emailed code and signs in as a member', async ({ page, request }) => {
    await page.goto(`${SITE}/login`);
    await page.getByRole('link', { name: 'Create an account' }).focus();
    await page.keyboard.press('Enter');
    await expect(page).toHaveTitle('Create an account on Riverside Library - Riverside Library');
    await expect(page.getByLabel('Time zone')).toHaveValue('Asia/Tokyo');
    await expectNoViolations(page);

    await page.getByRole('button', { name: 'Create my account' }).click();
    await expect(page.locator('#register-errors')).toBeFocused();
    await expect(page.getByLabel('First name')).toHaveAttribute('aria-invalid', 'true');
    await expectNoViolations(page);

    await page.getByLabel('First name').fill('Nia');
    await page.keyboard.press('Tab');
    await page.keyboard.type('Lind');
    await page.getByLabel('Email address').fill('nia@example.org');
    await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
    await page.keyboard.press('Enter');

    await expect(page).toHaveTitle('Confirm your email address - Riverside Library');
    await expect(page.getByText('We sent it by email to n•••@example.org.')).toBeVisible();
    await expectNoViolations(page);

    const email = await (await request.get(`${SITE}/_test/mail?to=nia@example.org`)).json() as { subject: string; text: string; html: string };
    expect(email.subject).toBe('Your code to confirm your email address for Riverside Library');
    for (const body of [email.text, email.html]) {
      expect(body).not.toMatch(/https?:\/\/|<a\b|www\.|127\.0\.0\.1/i);
    }
    const code = /^(\d{3}) (\d{3})$/m.exec(email.text);
    expect(code).not.toBeNull();

    await page.getByLabel('6-digit code').fill(`${code?.[1]} ${code?.[2]}`);
    await page.keyboard.press('Enter');
    await expect(page).toHaveTitle('Sign in - Riverside Library');
    await expect(page.getByRole('status')).toHaveText('Your email address is confirmed and your account is ready. Sign in with nia@example.org and your password.');
    await expectNoViolations(page);

    await page.getByLabel('Email address').fill('nia@example.org');
    await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(`${SITE}/`);
    expect((await page.request.get(`${SITE}/admin`)).status()).toBe(403);
  });

  test('who signs in before typing the code is sent to the code page', async ({ page }) => {
    await page.goto(`${SITE}/register`);
    await page.getByLabel('First name').fill('Nia');
    await page.getByLabel('Last name').fill('Lind');
    await page.getByLabel('Email address').fill('nia@example.org');
    await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
    await page.getByRole('button', { name: 'Create my account' }).click();
    await expect(page).toHaveTitle('Confirm your email address - Riverside Library');

    await page.goto(`${SITE}/login`);
    await page.getByLabel('Email address').fill('nia@example.org');
    await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
    await page.keyboard.press('Enter');

    await expect(page).toHaveTitle('Confirm your email address - Riverside Library');
    await expect(page.getByRole('status')).toHaveText('You haven’t confirmed your email address yet. Type the code we emailed you, or send a new one.');
  });
});
