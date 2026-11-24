// Domain registration (X-7) on the `domains` copy of the admin test site: a members-only site whose organisation lets
// people at example.org register. The sign-in page links to "Register with your organisation email"; another address
// is refused in the focused error summary, naming the domain; someone at example.org registers by keyboard with the
// emailed code and becomes a member. An admin turns it off for the site with the radio buttons on the sign-up screen.
// No axe violations in light and dark mode.
import { expect, test } from '@playwright/test';
import { ADMIN_SITES, PASSWORD, expectNoViolations, signIn } from './support/admin-site';

const SITE = ADMIN_SITES.domains;

test.beforeEach(async ({ request }) => {
  await request.get(`${SITE}/_test/reset`);
});

test('someone at the organisation’s domain registers by keyboard', async ({ page, request }) => {
  await page.goto(`${SITE}/login`);
  await expect(page.getByText('This site is for its members. Sign in to read it.')).toBeVisible();
  const link = page.getByRole('link', { name: 'Register with your organisation email' });
  await expect(link).toBeVisible();
  await expectNoViolations(page);
  await link.focus();
  await page.keyboard.press('Enter');

  await expect(page).toHaveTitle('Create an account on Riverside Library - Riverside Library');
  await expect(page.getByText('Use your email address at example.org.')).toBeVisible();
  await expectNoViolations(page);
  await page.getByLabel('First name').fill('Nia');
  await page.getByLabel('Last name').fill('Lind');
  await page.getByLabel('Email address').fill('nia@evil-example.org');
  await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
  await page.keyboard.press('Enter');

  await expect(page.locator('#register-errors')).toBeFocused();
  await expect(page.locator('#register-errors')).toContainText('Only email addresses at example.org can join this site.');
  await expect(page.getByLabel('Email address')).toHaveAttribute('aria-invalid', 'true');
  await expectNoViolations(page);

  await page.getByLabel('Email address').fill('Nia@Example.org');
  await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
  await page.keyboard.press('Enter');
  await expect(page).toHaveTitle('Confirm your email address - Riverside Library');

  const email = await (await request.get(`${SITE}/_test/mail?to=nia@example.org`)).json() as { text: string };
  const code = /^(\d{3}) (\d{3})$/m.exec(email.text);
  expect(code).not.toBeNull();
  await page.getByLabel('6-digit code').fill(`${code?.[1]}${code?.[2]}`);
  await page.keyboard.press('Enter');
  await expect(page.getByRole('status')).toHaveText('Your email address is confirmed and your account is ready. Sign in with nia@example.org and your password.');

  await page.getByLabel('Email address').fill('nia@example.org');
  await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(`${SITE}/`);
});

test('an admin turns it off for the site', async ({ page }) => {
  await signIn(page, SITE, 'ada@example.org', '/admin/settings/sign-up');
  const group = page.getByRole('group', { name: 'Registration with an organisation email address' });
  await expect(group.getByRole('radio', { name: 'On, as your organisation set it' })).toBeChecked();
  await expectNoViolations(page);
  await group.getByRole('radio', { name: 'On, as your organisation set it' }).focus();
  await page.keyboard.press('ArrowDown');
  await expect(group.getByRole('radio', { name: 'Off for this site' })).toBeChecked();
  await page.getByRole('button', { name: 'Save sign-up settings' }).click();
  await expect(page.getByRole('status')).toHaveText('The sign-up settings have been saved.');

  await page.context().clearCookies();
  await page.goto(`${SITE}/login`);
  await expect(page.getByRole('link', { name: 'Register with your organisation email' })).toHaveCount(0);
  expect((await page.goto(`${SITE}/register`))?.status()).toBe(404);
});
