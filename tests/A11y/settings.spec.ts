// Site settings and branding (C-7.3) on the admin test site: the settings are grouped in fieldsets with plain
// explanations, mistakes go to the focused error summary, saving is announced; the branding screen shows each
// picture with a meaningful alt text, and a refused upload is explained. No axe violations in light and dark mode;
// reflow at 320px.
import { expect, test } from '@playwright/test';
import { ADMIN_SITES, expectNoViolations, signIn } from './support/admin-site';

const SITE = ADMIN_SITES.settings;

test.beforeEach(async ({ request }) => {
  await request.get(`${SITE}/_test/reset`);
});

test('an admin changes the settings by keyboard and hears that they were saved', async ({ page }) => {
  await signIn(page, SITE, 'ada@example.org');
  await page.getByRole('navigation', { name: 'Admin' }).getByRole('link', { name: 'Site settings' }).click();

  await expect(page).toHaveTitle('Site settings - Admin - Riverside Library');
  await expect(page.getByRole('group', { name: 'Search & AI' })).toBeVisible();
  const crawlers = page.getByRole('group', { name: 'AI crawlers' });
  await expect(crawlers.getByRole('radio', { name: /^Allow all/ })).toBeChecked();
  await expectNoViolations(page);

  await crawlers.getByRole('radio', { name: /^Allow all/ }).focus();
  await page.keyboard.press('ArrowDown');
  await expect(crawlers.getByRole('radio', { name: /^AI search only/ })).toBeChecked();
  await page.getByLabel('Site name').fill('Riverside Reading Room');
  await page.getByLabel('About the site').fill('A small library by the river.');
  await page.getByRole('button', { name: 'Save settings' }).focus();
  await page.keyboard.press('Enter');

  await expect(page.getByRole('status')).toHaveText('The settings have been saved.');
  await expect(page).toHaveTitle('Site settings - Admin - Riverside Reading Room');
  await expect(page.getByLabel('About the site')).toHaveValue('A small library by the river.');
});

test('mistakes are explained in the error summary, which gets focus', async ({ page }) => {
  await signIn(page, SITE, 'ada@example.org', '/admin/settings');
  await page.getByLabel('Contact email address').fill('not an email');
  await page.getByLabel('Profiles on other sites').fill('example.social/@riverside');
  await page.getByRole('button', { name: 'Save settings' }).click();

  await expect(page).toHaveTitle('2 errors: Site settings - Admin - Riverside Library');
  await expect(page.locator('#site-settings-errors')).toBeFocused();
  await expect(page.getByLabel('Contact email address')).toHaveAttribute('aria-invalid', 'true');
  await expectNoViolations(page);
  await page.keyboard.press('Tab');
  await page.keyboard.press('Enter');
  await expect(page.getByLabel('Contact email address')).toBeFocused();
});

test('the branding screen shows each picture and explains a refused upload', async ({ page }) => {
  await signIn(page, SITE, 'ada@example.org', '/admin/settings/branding');

  await expect(page).toHaveTitle('Branding - Admin - Riverside Library');
  await expect(page.getByRole('img', { name: 'The logo now: Riverside Library' })).toBeVisible();
  await expect(page.getByRole('img', { name: 'The icon of Riverside Library now' })).toBeVisible();
  await expect(page.getByText('This is how it is cropped: 1200 × 630 pixels.')).toBeVisible();
  await expectNoViolations(page);

  const icon = page.getByRole('region', { name: 'Icon' });
  await icon.getByLabel('New picture').setInputFiles({ name: 'icon.png', mimeType: 'image/png', buffer: Buffer.from('not a picture') });
  await icon.getByRole('button', { name: 'Save the icon' }).click();
  await expect(page.locator('#branding-errors')).toBeFocused();
  await expect(page).toHaveTitle('1 error: Branding - Admin - Riverside Library');
  await expectNoViolations(page);

  await page.getByRole('region', { name: 'Logo' }).getByLabel('What it shows').fill('Riverside, in blue letters');
  await page.getByRole('button', { name: 'Save the logo' }).click();
  await expect(page.getByRole('status')).toHaveText('Logo: the text has been saved.');
  await expect(page.getByRole('img', { name: 'The logo now: Riverside, in blue letters' })).toBeVisible();
});

test('the settings screens reflow at 320px', async ({ page }) => {
  await page.setViewportSize({ width: 320, height: 640 });
  await signIn(page, SITE, 'ada@example.org', '/admin/settings');
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
  await page.goto(`${SITE}/admin/settings/branding`);
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
});

test('the audit log lists the change in a captioned table and filters it', async ({ page }) => {
  await signIn(page, SITE, 'ada@example.org', '/admin/settings');
  await page.getByLabel('Site name').fill('Riverside Reading Room');
  await page.getByRole('button', { name: 'Save settings' }).click();
  await page.getByRole('navigation', { name: 'Admin' }).getByRole('link', { name: 'Audit log' }).click();

  await expect(page).toHaveTitle('Audit log - Admin - Riverside Reading Room');
  const table = page.getByRole('table', { name: /entries, newest first/ });
  await expect(table.getByRole('row', { name: /Changed the site settings: Site name/ })).toBeVisible();
  await expectNoViolations(page);

  await page.getByLabel('What').selectOption('settings');
  await page.getByRole('button', { name: 'Show entries' }).click();
  await expect(page.getByRole('table', { name: '1 entry' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Download these entries as a CSV file' })).toHaveAttribute('href', '/admin/audit.csv?type=settings');
  await expectNoViolations(page);
});

test('the file storage screen opens from the settings by keyboard and goes back', async ({ page }) => {
  await signIn(page, SITE, 'ada@example.org', '/admin/settings');
  const link = page.getByRole('link', { name: 'File storage' });
  await link.focus();
  await expect(link).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(`${SITE}/admin/settings/storage`);
  await expect(page.getByRole('heading', { level: 1, name: 'File storage' })).toBeVisible();
  await expect(page.getByText('This site keeps its files on this server.')).toBeVisible();
  const back = page.getByRole('link', { name: 'Back to site settings' });
  await back.focus();
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(`${SITE}/admin/settings`);
});
