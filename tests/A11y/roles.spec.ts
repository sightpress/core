// Custom roles (X-4) on the admin test site: an admin creates a role by keyboard (name, then the permission
// checkboxes grouped by area, each with its description), gives it to someone on their page, and sees refusals on
// the right field with the boxes still ticked. Built-in roles only say what they can do; a role in use can't be
// deleted. No axe violations in light and dark mode.
import { expect, test } from '@playwright/test';
import { ADMIN_SITES, expectNoViolations, signIn } from './support/admin-site';

const ADMIN_SITE = ADMIN_SITES.roles;

test.beforeEach(async ({ request }) => {
  await request.get(`${ADMIN_SITE}/_test/reset`);
});

test('an admin creates a role by keyboard and gives it to someone', async ({ page }) => {
  await signIn(page, ADMIN_SITE, 'ada@example.org');
  await page.getByRole('navigation', { name: 'Admin' }).getByRole('link', { name: 'Roles' }).click();
  await expect(page).toHaveTitle('Roles - Admin - Riverside Library');
  await expect(page.getByRole('table', { name: 'Every role on the site' }).getByRole('row')).toHaveCount(5);
  await expectNoViolations(page);

  await page.getByRole('link', { name: 'Create a role' }).click();
  await expect(page.getByRole('heading', { level: 1, name: 'Create a role' })).toBeVisible();
  await expectNoViolations(page);
  await page.getByLabel('Name of the role').focus();
  await page.keyboard.type('Shop staff');
  const media = page.getByRole('group', { name: 'Media (optional)' });
  const upload = media.getByRole('checkbox', { name: 'Upload media' });
  await expect(upload).toHaveAccessibleDescription('Add pictures, video, audio and files.');
  await media.getByRole('checkbox', { name: 'Publish media' }).focus();
  await page.keyboard.press('Space');
  await page.keyboard.press('Tab');
  await expect(upload).toBeFocused();
  await page.keyboard.press('Space');
  await expect(upload).toBeChecked();
  await page.getByRole('button', { name: 'Create role' }).focus();
  await page.keyboard.press('Enter');

  await expect(page.getByRole('status')).toHaveText('Created the role “Shop staff”. Give it to people on their page in People.');
  await expect(page.getByRole('heading', { level: 1, name: 'Shop staff' })).toBeVisible();
  await expect(upload).toBeChecked();
  await expectNoViolations(page);

  await page.getByRole('navigation', { name: 'Admin' }).getByRole('link', { name: 'People' }).click();
  await page.getByRole('link', { name: 'Cleo' }).click();
  const shop = page.getByRole('group', { name: 'Role' }).getByRole('radio', { name: /^Shop staff/ });
  await shop.focus();
  await page.keyboard.press('Space');
  await page.getByRole('button', { name: 'Change role' }).focus();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('status')).toHaveText('Cleo’s role is now Shop staff.');

  await page.goto(`${ADMIN_SITE}/admin/roles/shop_staff/delete`);
  await expect(page.getByRole('heading', { level: 2, name: 'This role can’t be deleted' })).toBeVisible();
  await expect(page.getByText('1 person has the “Shop staff” role. Give them another role first.')).toBeVisible();
  await expectNoViolations(page);
});

test('a refusal is shown on its field and keeps the ticked boxes', async ({ page }) => {
  await signIn(page, ADMIN_SITE, 'ada@example.org', '/admin/roles/contributor');
  await page.getByLabel('Name of the role').fill('Editor');
  await page.getByRole('checkbox', { name: 'Publish pages' }).check();
  await page.getByRole('button', { name: 'Save role' }).click();

  await expect(page).toHaveTitle('1 error: Contributor - Admin - Riverside Library');
  await expect(page.locator('#role-errors')).toBeFocused();
  await expect(page.getByLabel('Name of the role')).toHaveAttribute('aria-invalid', 'true');
  await expect(page.getByRole('checkbox', { name: 'Publish pages' })).toBeChecked();
  await expectNoViolations(page);
});

test('built-in roles say what they can do and offer no form', async ({ page }) => {
  await signIn(page, ADMIN_SITE, 'ada@example.org', '/admin/roles/member');

  await expect(page.getByText('This role never has any permission')).toBeVisible();
  await expect(page.getByRole('checkbox')).toHaveCount(0);
  await expectNoViolations(page);
});

test('editors can’t open the roles screen', async ({ page }) => {
  await signIn(page, ADMIN_SITE, 'bea@example.org');

  await expect(page.getByRole('navigation', { name: 'Admin' }).getByRole('link', { name: 'Roles' })).toHaveCount(0);
  expect((await page.goto(`${ADMIN_SITE}/admin/roles`))?.status()).toBe(403);
});
