// The sign-in pages (C-3.3) and their six-digit codes (C-3.7) on a real site (tests/A11y/sign-in/index.php): no axe violations in light and dark
// mode, everything works by keyboard, errors are announced, and people go back where they came from.
import { expect, test, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const SITE = 'http://127.0.0.1:8098';
const PASSWORD = 'correct horse battery staple';
const WCAG_22_AA = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

async function expectNoViolations(page: Page): Promise<void> {
  for (const colorScheme of ['light', 'dark'] as const) {
    await page.emulateMedia({ colorScheme });
    const results = await new AxeBuilder({ page }).withTags(WCAG_22_AA).analyze();
    expect(results.violations, `${colorScheme} mode`).toEqual([]);
  }
}

async function signInByKeyboard(page: Page, email: string, password: string, remember = false): Promise<void> {
  await page.getByLabel('Email address').focus();
  await page.keyboard.type(email);
  await page.keyboard.press('Tab');
  await page.keyboard.type(password);
  await page.keyboard.press('Tab');
  if (remember) {
    await page.keyboard.press('Space');
  }
  await page.keyboard.press('Tab');
  await expect(page.getByRole('button', { name: 'Sign in' })).toBeFocused();
  await page.keyboard.press('Enter');
}

test.beforeEach(async ({ request }) => {
  await request.get(`${SITE}/_test/reset`);
});

test('the sign-in form is labelled, "keep me signed in" is off, and the tab order follows the page', async ({ page }) => {
  await page.goto(`${SITE}/login`);

  await expect(page).toHaveTitle('Sign in - Riverside Library');
  await expect(page.getByRole('heading', { level: 1, name: 'Sign in' })).toBeVisible();
  await expect(page.getByLabel('Email address')).toHaveAttribute('autocomplete', 'username');
  await expect(page.getByLabel('Password', { exact: true })).toHaveAttribute('autocomplete', 'current-password');
  const remember = page.getByRole('checkbox', { name: /Keep me signed in on this device for 30 days/ });
  await expect(remember).not.toBeChecked();
  await expect(remember).toHaveAccessibleDescription('Only tick this on a device that only you use.');
  await expectNoViolations(page);

  await page.keyboard.press('Tab');
  await expect(page.getByRole('link', { name: 'Skip to main content' })).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(page.getByRole('link', { name: 'Riverside Library' })).toBeFocused();
  for (const target of [
    page.getByLabel('Email address'),
    page.getByLabel('Password', { exact: true }),
    remember,
    page.getByRole('button', { name: 'Sign in' }),
    page.getByRole('link', { name: 'Forgot your password?' }),
    page.getByRole('link', { name: 'Accept an invitation' }),
    page.getByRole('link', { name: 'Set up your account with a setup code' }),
  ]) {
    await page.keyboard.press('Tab');
    await expect(target).toBeFocused();
  }
});

test('a wrong password is announced in the error summary, which gets focus', async ({ page }) => {
  await page.goto(`${SITE}/login`);
  await signInByKeyboard(page, 'ada@example.org', 'not the password');

  await expect(page).toHaveTitle('Error: Sign in - Riverside Library');
  await expect(page.locator('#sign-in-errors')).toBeFocused();
  await expect(page.locator('#sign-in-errors').getByRole('link')).toHaveText(['Enter a correct email address and password.']);
  await expect(page.getByLabel('Email address')).toHaveAttribute('aria-invalid', 'true');
  await expect(page.getByLabel('Password', { exact: true })).toHaveValue('');
  await expectNoViolations(page);

  await page.keyboard.press('Tab');
  await page.keyboard.press('Enter');
  await expect(page.getByLabel('Email address')).toBeFocused();
});

test('staff go back to the page they came from', async ({ page }) => {
  await page.goto(`${SITE}/login?return=/about`);
  await signInByKeyboard(page, 'ada@example.org', PASSWORD);

  await expect(page).toHaveURL(`${SITE}/about`);
});

test('members are never sent to the admin panel', async ({ page }) => {
  await page.goto(`${SITE}/login?return=/admin/pages`);
  await signInByKeyboard(page, 'max@example.org', PASSWORD);

  await expect(page).toHaveURL(`${SITE}/`);
});

test('signing out asks to confirm with a button, then says so in a status message', async ({ page }) => {
  await page.goto(`${SITE}/login`);
  await signInByKeyboard(page, 'ada@example.org', PASSWORD, true);
  await expect(page).toHaveURL(`${SITE}/admin`);
  expect((await page.context().cookies()).map((cookie) => cookie.name)).toContain('sightpress_remember');
  await page.goto(`${SITE}/logout`);

  await expect(page.getByRole('heading', { level: 1, name: 'Sign out' })).toBeVisible();
  await expectNoViolations(page);
  await page.getByRole('button', { name: 'Sign out' }).focus();
  await page.keyboard.press('Enter');

  await expect(page).toHaveURL(`${SITE}/login`);
  await expect(page.getByRole('status')).toHaveText('You have signed out.');
  await expectNoViolations(page);
  expect((await page.context().cookies()).map((cookie) => cookie.name)).not.toContain('sightpress_remember');
});

async function codeFor(request: import('@playwright/test').APIRequestContext, purpose: string, email = 'ada@example.org'): Promise<string> {
  return (await request.get(`${SITE}/_test/code?purpose=${purpose}&email=${encodeURIComponent(email)}`)).text();
}

test('forgot password: ask for a code, type it, then choose a new password', async ({ page, request }) => {
  await page.goto(`${SITE}/login`);
  await page.getByRole('link', { name: 'Forgot your password?' }).focus();
  await page.keyboard.press('Enter');

  await expect(page).toHaveTitle('Reset your password - Riverside Library');
  await expect(page.getByLabel('Email address')).toHaveAccessibleDescription('The address you sign in with.');
  await expectNoViolations(page);
  await page.getByLabel('Email address').focus();
  await page.keyboard.type('ada@example.org');
  await page.keyboard.press('Enter');

  await expect(page).toHaveURL(`${SITE}/login/forgot/code`);
  await expect(page.getByRole('heading', { level: 1, name: 'Enter your code' })).toBeVisible();
  const field = page.getByLabel('6-digit code');
  await expect(field).toHaveAttribute('inputmode', 'numeric');
  await expect(field).toHaveAttribute('autocomplete', 'one-time-code');
  await expect(field).toHaveAccessibleDescription(/We sent it by email to a\S*@example\.org\./);
  await expectNoViolations(page);

  // A wrong code is explained in the error summary, which gets focus. Nothing sends itself while typing.
  const code = await codeFor(request, 'password_reset');
  await field.focus();
  await page.keyboard.type(code === '000000' ? '111111' : '000000');
  await expect(page).toHaveURL(`${SITE}/login/forgot/code`);
  await page.keyboard.press('Enter');
  await expect(page.locator('#reset-code-errors')).toBeFocused();
  await expect(page.getByLabel('6-digit code')).toHaveAttribute('aria-invalid', 'true');
  await expectNoViolations(page);

  await page.getByLabel('6-digit code').fill(`${code.slice(0, 3)} ${code.slice(3)}`);
  await page.getByRole('button', { name: 'Continue' }).focus();
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(`${SITE}/login/password`);
  await expect(page.getByRole('heading', { level: 1, name: 'Choose a new password' })).toBeVisible();
  await expect(page.getByLabel('New password')).toHaveAttribute('autocomplete', 'new-password');
  await expectNoViolations(page);

  await page.getByLabel('New password').focus();
  await page.keyboard.type('short');
  await page.keyboard.press('Enter');
  await expect(page.locator('#choose-password-errors')).toBeFocused();
  await expect(page.getByLabel('New password')).toHaveAccessibleDescription(/Error: Your password must be at least 12 characters long\./);
  await expectNoViolations(page);

  await page.getByLabel('New password').fill('a completely new password');
  await page.getByLabel('New password').press('Enter');
  await expect(page).toHaveURL(`${SITE}/login`);
  await expect(page.getByRole('status')).toHaveText('Your password has been changed. Sign in with your new password.');

  await signInByKeyboard(page, 'ada@example.org', 'a completely new password');
  await expect(page).toHaveURL(`${SITE}/admin`);
});

test('"Send a new code" says in words how long to wait, and is never silently disabled', async ({ page }) => {
  await page.goto(`${SITE}/login/forgot`);
  await page.getByLabel('Email address').fill('ada@example.org');
  await page.getByRole('button', { name: 'Send me a code' }).click();
  await expect(page).toHaveURL(`${SITE}/login/forgot/code`);

  const resend = page.getByRole('button', { name: 'Send a new code' });
  await expect(resend).toBeEnabled();
  await resend.focus();
  await page.keyboard.press('Enter');
  await expect(page.locator('#reset-code-errors')).toBeFocused();
  await expect(page.locator('#reset-code-errors')).toContainText(/Wait \d+ seconds?, then send a new code\./);
  await expectNoViolations(page);
});

test('an address without an account gets the same code page', async ({ page }) => {
  await page.goto(`${SITE}/login/forgot`);
  await page.getByLabel('Email address').fill('nobody@example.org');
  await page.getByRole('button', { name: 'Send me a code' }).click();

  await expect(page).toHaveURL(`${SITE}/login/forgot/code`);
  await expect(page.getByRole('heading', { level: 1, name: 'Enter your code' })).toBeVisible();
});

test('a setup code lets a new admin choose their first password', async ({ page, request }) => {
  await page.goto(`${SITE}/login`);
  await page.getByRole('link', { name: 'Set up your account with a setup code' }).click();

  await expect(page).toHaveTitle('Set up your account - Riverside Library');
  await expectNoViolations(page);
  // Ada already has a password: a setup code is no use to her.
  await page.getByLabel('Email address').fill('ada@example.org');
  await page.getByLabel('6-digit code').fill(await codeFor(request, 'account_setup'));
  await page.getByRole('button', { name: 'Continue' }).click();
  await expect(page.locator('#setup-account-errors')).toBeFocused();
  await expectNoViolations(page);

  const code = await codeFor(request, 'account_setup', 'nia@example.org');
  await page.getByLabel('Email address').fill('nia@example.org');
  await page.getByLabel('6-digit code').fill(code);
  await page.getByRole('button', { name: 'Continue' }).click();

  await expect(page).toHaveURL(`${SITE}/login/password`);
  await expect(page.getByRole('heading', { level: 1, name: 'Choose your password' })).toBeVisible();
  await expectNoViolations(page);
});

test('the old link-based pages are gone', async ({ page }) => {
  for (const path of ['/password/forgot', '/password/reset/not-a-real-link']) {
    const response = await page.goto(`${SITE}${path}`);
    expect(response?.status(), path).toBe(404);
  }
});
