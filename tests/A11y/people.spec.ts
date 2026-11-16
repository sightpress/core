// Invitations (C-3.4) on the admin test site: admins and editors invite by keyboard (editors only contributors),
// mistakes are explained in the focused error summary, and the invited person sets up their account with their
// browser's time zone already chosen. No axe violations in light and dark mode.
import { expect, test } from '@playwright/test';
import { ADMIN_SITES, PASSWORD, expectNoViolations, signIn } from './support/admin-site';

const ADMIN_SITE = ADMIN_SITES.people;

test.beforeEach(async ({ request }) => {
  await request.get(`${ADMIN_SITE}/_test/reset`);
});

test('an admin invites someone by keyboard and hears that it was sent', async ({ page }) => {
  await signIn(page, ADMIN_SITE, 'ada@example.org');
  await page.getByRole('navigation', { name: 'Admin' }).getByRole('link', { name: 'Invite someone' }).click();

  await expect(page).toHaveTitle('Invite someone - Admin - Riverside Library');
  const roles = page.getByRole('group', { name: 'Role' });
  await expect(roles.getByRole('radio')).toHaveCount(4);
  await expectNoViolations(page);

  await page.getByLabel('Email address').fill('zoe@example.org');
  await page.keyboard.press('Tab');
  await expect(roles.getByRole('radio').first()).toBeFocused();
  await page.keyboard.press('ArrowDown');
  await expect(roles.getByRole('radio', { name: /^Editor/ })).toBeChecked();
  await page.keyboard.press('Tab');
  await expect(page.getByLabel('First name (optional)')).toBeFocused();
  await page.keyboard.type('Zoë');
  await page.getByRole('button', { name: 'Send invitation' }).focus();
  await page.keyboard.press('Enter');

  await expect(page.getByRole('status')).toHaveText('We have sent an invitation to zoe@example.org to join as editor. The link works for 7 days.');
  await expectNoViolations(page);
});

test('missing answers are explained in the error summary, which gets focus', async ({ page }) => {
  await signIn(page, ADMIN_SITE, 'ada@example.org', '/admin/users/invite');
  await page.getByRole('button', { name: 'Send invitation' }).click();

  await expect(page).toHaveTitle('2 errors: Invite someone - Admin - Riverside Library');
  await expect(page.locator('#invite-errors')).toBeFocused();
  await expect(page.getByLabel('Email address')).toHaveAttribute('aria-invalid', 'true');
  await expectNoViolations(page);

  await page.keyboard.press('Tab');
  await page.keyboard.press('Enter');
  await expect(page.getByLabel('Email address')).toBeFocused();
});

test('editors may only invite contributors', async ({ page }) => {
  await signIn(page, ADMIN_SITE, 'bea@example.org', '/admin/users/invite');

  const roles = page.getByRole('group', { name: 'Role' }).getByRole('radio');
  await expect(roles).toHaveCount(1);
  await expect(roles.first()).toBeChecked();
  await expect(roles.first()).toHaveAccessibleName(/^Contributor/);
  await expectNoViolations(page);
});

test.describe('the invited person', () => {
  test.use({ timezoneId: 'Asia/Tokyo' });

  test('sets up their account with their own time zone and is asked to sign in', async ({ page, request }) => {
    const link = await (await request.get(`${ADMIN_SITE}/_test/invitation?email=zoe@example.org&role=editor&first_name=Zo%C3%AB`)).text();
    await page.goto(`${ADMIN_SITE}${link}`);

    await expect(page).toHaveTitle('Join Riverside Library - Riverside Library');
    await expect(page.getByLabel('First name')).toHaveValue('Zoë');
    await expect(page.getByLabel('Time zone')).toHaveValue('Asia/Tokyo');
    await expectNoViolations(page);

    await page.getByLabel('Last name').fill('Ødegaard');
    await page.getByLabel('Password').fill('short');
    await page.getByRole('button', { name: 'Create my account' }).click();
    await expect(page.locator('#accept-errors')).toBeFocused();
    await expect(page.getByLabel('Password')).toHaveAttribute('aria-invalid', 'true');
    await expectNoViolations(page);

    await page.getByLabel('Password').fill(PASSWORD);
    await page.getByRole('button', { name: 'Create my account' }).click();
    await expect(page).toHaveURL(`${ADMIN_SITE}/login`);
    await expect(page.getByRole('status')).toHaveText('Your account is ready. Sign in with zoe@example.org and your new password.');

    await page.goto(`${ADMIN_SITE}${link}`);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('This invitation no longer works');
    await expectNoViolations(page);
  });
});
