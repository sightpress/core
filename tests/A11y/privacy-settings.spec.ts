// Settings → Privacy (GD-2) on the admin test site: an admin chooses how long each kind of personal data is kept,
// by keyboard, and hears that it was saved; every choice says what it does. No axe violations in light and dark
// mode, in forced colours and at 320px; an editor can't open it.
import { expect, test } from '@playwright/test';
import { ADMIN_SITES, expectNoViolations, signIn } from './support/admin-site';

const SITE = ADMIN_SITES.privacy;

test.beforeEach(async ({ request }) => {
  await request.get(`${SITE}/_test/reset`);
});

test('an admin chooses how long text messages are kept, by keyboard', async ({ page }) => {
  await signIn(page, SITE, 'ada@example.org', '/admin/settings');
  await page.getByRole('navigation', { name: 'Admin' }).getByRole('link', { name: 'Privacy' }).click();

  await expect(page).toHaveTitle('Privacy - Admin - Riverside Library');
  await expect(page.getByRole('heading', { level: 2, name: 'How long personal data is kept' })).toBeVisible();
  const sms = page.getByLabel('Text messages sent');
  await expect(sms).toHaveAccessibleDescription('The record of each text message, with its text and the masked number, is deleted after this.');
  await expect(sms.locator('option:checked')).toHaveText('90 days (suggested)');
  await expectNoViolations(page);

  await sms.focus();
  await sms.selectOption('30');
  await page.getByRole('button', { name: 'Save' }).focus();
  await page.keyboard.press('Enter');

  await expect(page.getByRole('status')).toHaveText('How long personal data is kept has been saved. Older records are deleted within a day.');
  await expect(page.getByLabel('Text messages sent').locator('option:checked')).toHaveText('30 days');

  for (const scheme of ['dark', 'light'] as const) {
    await page.emulateMedia({ colorScheme: scheme });
    await expectNoViolations(page);
  }
  await page.emulateMedia({ forcedColors: 'active' });
  await expectNoViolations(page);
  await page.emulateMedia({ forcedColors: 'none' });
  await page.setViewportSize({ width: 320, height: 640 });
  await expectNoViolations(page);
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
});

test('an editor can\'t open it', async ({ page }) => {
  await signIn(page, SITE, 'bea@example.org', '/admin/settings/privacy');

  await expect(page.getByRole('heading', { level: 1 })).not.toHaveText('Privacy');
});

test.describe('without JavaScript', () => {
  test.use({ javaScriptEnabled: false });

  test('the choices are saved with a plain form', async ({ page }) => {
    await signIn(page, SITE, 'ada@example.org', '/admin/settings/privacy');
    await page.getByLabel('Audit log').selectOption('36');
    await page.getByRole('button', { name: 'Save' }).click();

    await expect(page.getByRole('status')).toHaveText('How long personal data is kept has been saved. Older records are deleted within a day.');
    await expect(page.getByLabel('Audit log').locator('option:checked')).toHaveText('36 months');
  });
});
