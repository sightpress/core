// The menu editor (C-6.3) on the admin test site: an editor adds pages and a link by keyboard, moves entries with
// buttons whose names say which entry they move, and focus comes back to the same button (or the opposite one when
// that button is gone) with the result announced. No axe violations in light and dark mode; reflows at 320px.
import { expect, test } from '@playwright/test';
import { ADMIN_SITES, expectNoViolations, signIn } from './support/admin-site';

const ADMIN_SITE = ADMIN_SITES.menus;

test.beforeEach(async ({ request }) => {
  await request.get(`${ADMIN_SITE}/_test/reset`);
});

test('an editor builds the main menu by keyboard and focus follows the entry', async ({ page }) => {
  // Many page loads on PHP's single-threaded web server.
  test.setTimeout(60_000);
  await signIn(page, ADMIN_SITE, 'bea@example.org');
  await page.getByRole('navigation', { name: 'Admin' }).getByRole('link', { name: 'Menus' }).click();

  await expect(page).toHaveTitle('Menus - Admin - Riverside Library');
  await expect(page.getByText('This menu is empty. Add a page or a link below.')).toHaveCount(2);
  await expectNoViolations(page);

  const addPage = page.getByRole('region', { name: 'Add a page' });
  for (const title of ['Opening hours', 'Volunteer with us']) {
    await addPage.getByRole('radio', { name: 'Main menu' }).check();
    await addPage.getByLabel('Page', { exact: true }).selectOption({ label: `${title} (not published)` });
    await addPage.getByRole('button', { name: 'Add page' }).press('Enter');
    await expect(page.getByRole('status')).toContainText(`${title} has been added to the main menu.`);
    await expect(page.locator(`span[tabindex="-1"]:focus`)).toHaveText(title);
  }

  const addLink = page.getByRole('region', { name: 'Add a link' });
  await addLink.getByRole('radio', { name: 'Footer menu' }).check();
  await addLink.getByLabel('Link text').fill('Catalogue');
  await addLink.getByLabel('Address').fill('javascript:alert(1)');
  await addLink.getByRole('button', { name: 'Add link' }).press('Enter');
  const summary = page.getByRole('region', { name: 'There is a problem' });
  await expect(summary).toBeFocused();
  await expect(page).toHaveTitle('1 error: Menus - Admin - Riverside Library');
  await expectNoViolations(page);
  await summary.getByRole('link').press('Enter');
  await expect(page.getByLabel('Address')).toBeFocused();
  await page.getByLabel('Address').fill('https://catalogue.example.org');
  await page.getByRole('button', { name: 'Add link' }).press('Enter');
  await expect(page.getByRole('status')).toContainText('Catalogue has been added to the footer menu.');

  const main = page.getByRole('region', { name: 'Main menu' });
  await main.getByRole('button', { name: 'Move in, Volunteer with us' }).press('Enter');
  await expect(page.getByRole('status')).toContainText('Volunteer with us has been moved in, under the entry before it.');
  await expect(main.getByRole('button', { name: 'Move out, Volunteer with us' })).toBeFocused();
  await expect(main.getByRole('listitem').filter({ hasText: 'Opening hours' }).getByRole('list')).toContainText('Volunteer with us');

  await main.getByRole('button', { name: 'Move out, Volunteer with us' }).press('Enter');
  await main.getByRole('button', { name: 'Move up, Volunteer with us' }).press('Enter');
  await expect(page.getByRole('status')).toContainText('Volunteer with us has been moved up.');
  await expect(main.getByRole('button', { name: 'Move down, Volunteer with us' })).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(main.getByRole('button', { name: 'Move down, Volunteer with us' })).toHaveCount(0);
  await expect(main.getByRole('button', { name: 'Move up, Volunteer with us' })).toBeFocused();
  await main.getByRole('button', { name: 'Move up, Volunteer with us' }).press('Enter');
  await expectNoViolations(page);

  await main.getByRole('button', { name: 'Remove, Opening hours' }).press('Enter');
  await expect(page.getByRole('heading', { name: 'Main menu' })).toBeFocused();
  await expect(main.getByRole('listitem')).toHaveCount(1);

  await page.setViewportSize({ width: 320, height: 640 });
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
  await expectNoViolations(page);
});

test('contributors cannot open the menu editor', async ({ page }) => {
  await signIn(page, ADMIN_SITE, 'cleo@example.org');

  await expect(page.getByRole('navigation', { name: 'Admin' }).getByRole('link', { name: 'Menus' })).toHaveCount(0);
  expect((await page.goto(`${ADMIN_SITE}/admin/menus`))?.status()).toBe(403);
});
