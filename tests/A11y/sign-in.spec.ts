// The sign-in pages (C-3.3) on a real site (tests/A11y/sign-in/index.php): no axe violations in light and dark
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

test('forgot password: ask for a link, then choose a new password with it', async ({ page, request }) => {
  await page.goto(`${SITE}/login`);
  await page.getByRole('link', { name: 'Forgot your password?' }).focus();
  await page.keyboard.press('Enter');

  await expect(page).toHaveTitle('Reset your password - Riverside Library');
  await expect(page.getByLabel('Email address')).toHaveAccessibleDescription('The address you sign in with.');
  await expectNoViolations(page);
  await page.getByLabel('Email address').focus();
  await page.keyboard.type('ada@example.org');
  await page.keyboard.press('Enter');
  await expect(page.getByRole('heading', { level: 1, name: 'Check your email' })).toBeVisible();
  await expectNoViolations(page);

  const link = await (await request.get(`${SITE}/_test/password-link`)).text();
  await page.goto(`${SITE}${link}`);
  await expect(page.getByRole('heading', { level: 1, name: 'Choose a new password' })).toBeVisible();
  await expect(page.getByLabel('New password')).toHaveAttribute('autocomplete', 'new-password');
  await expectNoViolations(page);

  await page.getByLabel('New password').focus();
  await page.keyboard.type('short');
  await page.keyboard.press('Enter');
  await expect(page.locator('#reset-password-errors')).toBeFocused();
  await expect(page.getByLabel('New password')).toHaveAccessibleDescription(/Error: Your password must be at least 12 characters long\./);
  await expectNoViolations(page);

  await page.getByLabel('New password').fill('a completely new password');
  await page.getByLabel('New password').press('Enter');
  await expect(page).toHaveURL(`${SITE}/login`);
  await expect(page.getByRole('status')).toHaveText('Your password has been changed. Sign in with your new password.');

  await signInByKeyboard(page, 'ada@example.org', 'a completely new password');
  await expect(page).toHaveURL(`${SITE}/admin`);
});

test('a link that no longer works says what to do next', async ({ page }) => {
  const response = await page.goto(`${SITE}/password/reset/not-a-real-link`);

  expect(response?.status()).toBe(410);
  await expect(page.getByRole('heading', { level: 1, name: 'This link no longer works' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Ask for a new link' })).toHaveAttribute('href', '/password/forgot');
  await expectNoViolations(page);
});
