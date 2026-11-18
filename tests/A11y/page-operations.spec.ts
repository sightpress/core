// Page operations (P-1) on the admin test site: moving a page with the "Place in the site" buttons and the "Move to"
// list, copying it, putting it in the trash and deleting it for good through the confirmation page, and the
// redirects screen (adding one, its errors in the focused summary, removing one). Every result is announced in the
// status region; no axe violations in light and dark mode.
import { expect, test } from '@playwright/test';
import { ADMIN_SITES, expectNoViolations, signIn } from './support/admin-site';

const SITE = ADMIN_SITES.operations;

test.beforeEach(async ({ request }) => {
  await request.get(`${SITE}/_test/reset`);
});

test('a page moves one step or anywhere in the site by keyboard, and the move is announced', async ({ page }) => {
  await signIn(page, SITE, 'bea@example.org', '/admin/pages');
  await page.getByRole('link', { name: 'Volunteer with us' }).click();
  const place = page.getByRole('region', { name: 'Place in the site' });
  await expect(place.getByText('The page is at the top level of the site.')).toBeVisible();
  await expectNoViolations(page);

  await place.getByRole('button', { name: 'Move under the page before it' }).focus();
  await page.keyboard.press('Enter');
  const status = page.getByRole('status');
  await expect(status).toHaveText('Moved the page. Its address is now /summer-reading-challenge/volunteer-with-us; links to its old address still lead to it.');
  await expect(place.getByText('The page is under: Summer reading challenge')).toBeVisible();

  await place.getByLabel('Move to').focus();
  await place.getByLabel('Move to').selectOption({ label: 'The top level of the site' });
  await page.keyboard.press('Tab');
  await expect(place.getByRole('button', { name: 'Move the page' })).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(status).toHaveText('Moved the page. Its address is now /volunteer-with-us; links to its old address still lead to it.');
  await expect(place.getByText('The page is at the top level of the site.')).toBeVisible();
  await expectNoViolations(page);
});

test('a copy opens as a new draft, and a page in the trash is deleted for good after a confirmation', async ({ page }) => {
  await signIn(page, SITE, 'bea@example.org', '/admin/pages');
  await page.getByRole('link', { name: 'Volunteer with us' }).click();
  await page.getByRole('button', { name: 'Copy this page' }).focus();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('heading', { level: 1, name: 'Edit “Copy of Volunteer with us”' })).toBeVisible();
  await expect(page.getByRole('status')).toHaveText('Copied the page. This is the copy, a new draft: change its title and address before you publish it.');

  await page.getByRole('button', { name: 'Put in the trash' }).focus();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('status')).toHaveText('Put in the trash. Its address now tells visitors the page is gone.');
  await expect(page.getByText('State: In the trash')).toBeVisible();
  await expectNoViolations(page);

  await page.getByRole('link', { name: 'Delete this page for good' }).focus();
  await page.keyboard.press('Enter');
  await expect(page).toHaveTitle('Delete “Copy of Volunteer with us” for good? - Admin - Riverside Library');
  await expectNoViolations(page);
  await page.getByRole('button', { name: 'Delete for good' }).focus();
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(`${SITE}/admin/pages?state=trash`);
  await expect(page.getByRole('status')).toHaveText('Deleted “Copy of Volunteer with us” for good. Its address now tells visitors the page is gone.');
  await expect(page.getByRole('heading', { level: 2, name: '0 pages match' })).toBeVisible();
  await expectNoViolations(page);
});

test('redirects are added and removed by keyboard, with errors in the focused summary', async ({ page }) => {
  await signIn(page, SITE, 'bea@example.org', '/admin/redirects');
  await expect(page.getByRole('heading', { level: 1, name: 'Redirects' })).toBeVisible();
  await expect(page.getByText('There are no redirects yet.')).toBeVisible();
  await expectNoViolations(page);

  await page.getByLabel('Old address').fill('/opening-hours');
  await page.getByLabel('New address').fill('/volunteer-with-us');
  await page.getByLabel('New address').press('Enter');
  await expect(page.getByRole('heading', { name: 'There is a problem' })).toBeVisible();
  await expect(page.locator('.error-summary')).toBeFocused();
  await expectNoViolations(page);

  await page.getByLabel('Old address').fill('/old-hours');
  await page.getByLabel('New address').fill('/opening-hours');
  await page.getByLabel('New address').press('Enter');
  await expect(page.getByRole('status')).toHaveText('Added the redirect: /old-hours now leads to /opening-hours.');
  await expect(page.getByRole('rowheader', { name: '/old-hours' })).toBeVisible();
  await expectNoViolations(page);

  await page.getByRole('button', { name: 'Remove the redirect from /old-hours' }).focus();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('status')).toHaveText('Removed the redirect from /old-hours. That address now answers “not found”.');
  await expect(page.getByText('There are no redirects yet.')).toBeVisible();
});
