// My account (C-3.6) on the admin test site: members and staff change their details and picture by keyboard, results
// are announced, refused pictures are explained, and there are no axe violations in light and dark mode.
import { expect, test } from '@playwright/test';
import { ADMIN_SITES, expectNoViolations, signIn } from './support/admin-site';

const ADMIN_SITE = ADMIN_SITES.account;

// A 1 × 1 PNG.
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64');

test.beforeEach(async ({ request }) => {
  await request.get(`${ADMIN_SITE}/_test/reset`);
});

test('a member changes their details by keyboard and hears that they were saved', async ({ page }) => {
  await signIn(page, ADMIN_SITE, 'max@example.org', '/account');

  await expect(page).toHaveTitle('My account - Riverside Library');
  await expect(page.getByRole('heading', { level: 1, name: 'My account' })).toBeVisible();
  await expect(page.getByRole('img', { name: 'Max' })).toHaveAttribute('src', /^\/avatars\/[0-9a-f]{32}\.svg$/);
  await expect(page.getByLabel('Display name (optional)')).toHaveAccessibleDescription('Shown next to your work and in emails. Shown as “Max” if left empty.');
  await expect(page.getByLabel('Time zone')).toHaveValue('Europe/London');
  await expect(page.getByRole('link', { name: 'Go to the admin panel' })).toHaveCount(0);
  await expectNoViolations(page);

  await page.getByLabel('First name').focus();
  await page.keyboard.press('Tab');
  await expect(page.getByLabel('Last name')).toBeFocused();
  await page.keyboard.press('Tab');
  await page.keyboard.type('Maxi');
  await page.keyboard.press('Tab');
  await expect(page.getByLabel('Time zone')).toBeFocused();
  await page.getByLabel('Time zone').selectOption('Asia/Tokyo');
  await page.keyboard.press('Tab');
  await expect(page.getByLabel('Language (optional)')).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(page.getByRole('button', { name: 'Save details' })).toBeFocused();
  await page.keyboard.press('Enter');

  await expect(page).toHaveURL(`${ADMIN_SITE}/account`);
  await expect(page.getByRole('status')).toHaveText('Your details have been saved.');
  await expect(page.getByLabel('Display name (optional)')).toHaveValue('Maxi');
  await expect(page.getByLabel('Time zone')).toHaveValue('Asia/Tokyo');
  await expectNoViolations(page);
});

test('empty names are explained in the error summary, which gets focus', async ({ page }) => {
  await signIn(page, ADMIN_SITE, 'max@example.org', '/account');
  await page.getByLabel('First name').fill('');
  await page.getByRole('button', { name: 'Save details' }).click();

  await expect(page).toHaveTitle('Error: My account - Riverside Library');
  await expect(page.locator('#details-errors')).toBeFocused();
  await expect(page.locator('#details-errors').getByRole('link')).toHaveText(['Enter first name']);
  await expect(page.getByLabel('First name')).toHaveAttribute('aria-invalid', 'true');
  await expectNoViolations(page);

  await page.keyboard.press('Tab');
  await page.keyboard.press('Enter');
  await expect(page.getByLabel('First name')).toBeFocused();
});

test('a picture is uploaded and removed, and each result is announced', async ({ page }) => {
  await signIn(page, ADMIN_SITE, 'cleo@example.org', '/account');
  await expect(page.getByLabel('New picture')).toHaveAttribute('accept', 'image/jpeg,image/png,image/webp');
  await expect(page.getByLabel('New picture')).toHaveAccessibleDescription('A JPEG, PNG or WebP picture, up to 5 MB. It is cropped to a square from the middle.');

  await page.getByLabel('New picture').setInputFiles({ name: 'me.png', mimeType: 'image/png', buffer: PNG });
  await page.getByRole('button', { name: 'Upload picture' }).click();

  await expect(page.getByRole('status')).toHaveText('Your new picture has been saved.');
  await expect(page.getByRole('img', { name: 'Cleo' })).toHaveAttribute('src', /^\/avatars\/[0-9a-f]{32}-256\.png$/);
  await expect(page.getByRole('link', { name: 'Go to the admin panel' })).toBeVisible();
  await expectNoViolations(page);

  await page.getByRole('button', { name: 'Remove picture' }).focus();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('status')).toHaveText('Your picture has been removed. Your initials are shown instead.');
  await expect(page.getByRole('img', { name: 'Cleo' })).toHaveAttribute('src', /\.svg$/);
  await expect(page.getByRole('button', { name: 'Remove picture' })).toHaveCount(0);
});

test('an SVG picture is refused with a clear message', async ({ page }) => {
  await signIn(page, ADMIN_SITE, 'cleo@example.org', '/account');
  await page.getByLabel('New picture').setInputFiles({
    name: 'me.svg',
    mimeType: 'image/svg+xml',
    buffer: Buffer.from('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
  });
  await page.getByRole('button', { name: 'Upload picture' }).click();

  await expect(page).toHaveTitle('Error: My account - Riverside Library');
  await expect(page.locator('#picture-errors')).toBeFocused();
  await expect(page.locator('#picture-errors').getByRole('link')).toHaveText([
    "SVG pictures can't be used as a profile picture. Upload a JPEG, PNG or WebP picture.",
  ]);
  await expect(page.getByLabel('New picture')).toHaveAttribute('aria-invalid', 'true');
  await expectNoViolations(page);
});

test('the admin header links to My account, which reflows at 320px', async ({ page }) => {
  await page.setViewportSize({ width: 320, height: 640 });
  await signIn(page, ADMIN_SITE, 'ada@example.org');
  await page.getByRole('link', { name: 'My account' }).click();

  await expect(page).toHaveURL(`${ADMIN_SITE}/account`);
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
  await expectNoViolations(page);
});
