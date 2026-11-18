// Scheduled publishing (P-3) on the admin test site: an editor sets when a page is published and taken off the site,
// in their own time zone, by keyboard; the result is announced in the status region, the page list says it in
// words, a wrong time is explained, and the schedule can be cleared. No axe violations in light and dark mode.
import { expect, test } from '@playwright/test';
import { ADMIN_SITES, expectNoViolations, signIn } from './support/admin-site';

const SITE = ADMIN_SITES.schedule;

test.beforeEach(async ({ request }) => {
  await request.get(`${SITE}/_test/reset`);
});

test('a page is scheduled and the schedule cleared by keyboard, in the editor’s time zone', async ({ page }) => {
  await signIn(page, SITE, 'bea@example.org', '/admin/pages');
  await page.getByRole('link', { name: 'Volunteer with us' }).click();
  const schedule = page.getByRole('region', { name: 'Schedule' });
  await expect(schedule.getByText('Nothing is scheduled.')).toBeVisible();
  await expect(schedule.getByText('Times are in your time zone, Europe/London.')).toBeVisible();
  await expectNoViolations(page);

  await schedule.getByLabel('Publish at (optional)').fill('2030-06-01T09:00');
  await schedule.getByLabel('Take off the site at (optional)').fill('2030-05-01T09:00');
  await schedule.getByRole('button', { name: 'Save the schedule' }).focus();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('status')).toHaveText('The page must be taken off the site after it is published.');

  await schedule.getByLabel('Take off the site at (optional)').fill('2030-06-30T18:00');
  await schedule.getByRole('button', { name: 'Save the schedule' }).focus();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('status')).toHaveText('Scheduled: the page is published at 2030-06-01 09:00 and taken off the site at 2030-06-30 18:00 (Europe/London time).');
  await expect(schedule.getByLabel('Publish at (optional)')).toHaveValue('2030-06-01T09:00');
  await expect(schedule.getByText(/^To be published at/)).toBeVisible();
  await expectNoViolations(page);

  await page.goto(`${SITE}/admin/pages`);
  await expect(page.getByText(/Draft · To be published at .* · To be taken off the site at/)).toBeVisible();
  await page.getByRole('link', { name: 'Volunteer with us' }).click();

  await schedule.getByRole('button', { name: 'Clear the schedule' }).focus();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('status')).toHaveText('The schedule is cleared: nothing happens to the page on its own.');
  await expect(schedule.getByText('Nothing is scheduled.')).toBeVisible();
});
