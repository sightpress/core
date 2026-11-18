// Menu entries (MN-1) on the admin test site: an editor opens an entry's "Change" link from the menu editor by
// keyboard, makes it for members only, saves, and comes back to the menus with the result announced, focus on the
// entry and "only shown to members who are signed in" said in words. No axe violations in light and dark mode.
import { expect, test } from '@playwright/test';
import { ADMIN_SITES, expectNoViolations, signIn } from './support/admin-site';

const SITE = ADMIN_SITES.menuentries;

test.beforeEach(async ({ request }) => {
  await request.get(`${SITE}/_test/reset`);
});

test('an editor changes a menu entry by keyboard and hears the result', async ({ page }) => {
  await signIn(page, SITE, 'bea@example.org', '/admin/menus');
  const addLink = page.getByRole('region', { name: 'Add a link' });
  await addLink.getByRole('radio', { name: 'Footer menu' }).check();
  await addLink.getByLabel('Link text').fill('Catalogue');
  await addLink.getByLabel('Address').fill('https://catalogue.example');
  await addLink.getByRole('button', { name: 'Add link' }).press('Enter');
  await expect(page.getByRole('status')).toContainText('Catalogue has been added to the footer menu.');

  await page.getByRole('link', { name: 'Change Catalogue' }).focus();
  await page.keyboard.press('Enter');
  await expect(page).toHaveTitle('Change the menu entry “Catalogue” - Admin - Riverside Library');
  await expect(page.getByLabel('Text in British English')).toHaveValue('Catalogue');
  await expectNoViolations(page);

  await page.getByLabel('Text in British English').fill('');
  await page.getByRole('button', { name: 'Save the entry' }).press('Enter');
  await expect(page.getByRole('region', { name: 'There is a problem' })).toBeFocused();
  await expectNoViolations(page);

  await page.getByLabel('Text in British English').fill('Library catalogue');
  await page.getByLabel('Only show this entry to members who are signed in (optional)').focus();
  await page.keyboard.press('Space');
  await page.getByRole('button', { name: 'Save the entry' }).press('Enter');

  await expect(page).toHaveTitle('Menus - Admin - Riverside Library');
  await expect(page.getByRole('status')).toHaveText('Library catalogue has been changed.');
  await expect(page.locator('span[tabindex="-1"]:focus')).toHaveText('Library catalogue');
  await expect(page.getByText('Link: https://catalogue.example – only shown to members who are signed in')).toBeVisible();
  await expectNoViolations(page);
});
