// Site languages and translations (I-1) on the admin test site: an admin adds a language from the settings by
// keyboard (the list of languages is a native select), hears that it was added, removes it after a confirmation page
// that says what happens, and starts a translation of a page from the editor's "Translations" table. No axe
// violations in light and dark mode; reflow at 320px.
import { expect, test } from '@playwright/test';
import { ADMIN_SITES, expectNoViolations, signIn } from './support/admin-site';

const SITE = ADMIN_SITES.languages;

test.beforeEach(async ({ request }) => {
  await request.get(`${SITE}/_test/reset`);
});

test('an admin adds and removes a language by keyboard', async ({ page }) => {
  await signIn(page, SITE, 'ada@example.org', '/admin/settings');
  await page.getByRole('link', { name: 'Change the languages' }).focus();
  await page.keyboard.press('Enter');

  await expect(page).toHaveTitle('Languages - Admin - Riverside Library');
  await expect(page.getByRole('table', { name: 'Languages of the site, main language first' })).toBeVisible();
  await expectNoViolations(page);

  await page.getByLabel('Language to add').selectOption('es');
  await page.getByRole('button', { name: 'Add language' }).focus();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('status')).toHaveText('Added Spanish. Translate pages into it from each page’s editor.');
  const spanish = page.getByRole('row', { name: /Spanish/ });
  await expect(spanish.getByRole('cell', { name: '/es/' })).toBeVisible();
  await expect(spanish.getByRole('button', { name: 'Make Spanish the main language' })).toBeVisible();
  await expectNoViolations(page);

  await spanish.getByRole('link', { name: 'Remove Spanish' }).focus();
  await page.keyboard.press('Enter');
  await expect(page).toHaveTitle('Remove Spanish? - Admin - Riverside Library');
  await expect(page.getByText('The translations are kept. Add Spanish again to bring them back as they were.')).toBeVisible();
  await expectNoViolations(page);
  await page.getByRole('button', { name: 'Remove Spanish' }).focus();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('status')).toHaveText('Removed Spanish. Its translations are kept: add the language again to bring them back.');
  await expect(page.getByRole('row', { name: /Spanish/ })).toHaveCount(0);
});

test('a page is translated from the editor’s translations table', async ({ page }) => {
  await signIn(page, SITE, 'ada@example.org', '/admin/settings/languages');
  await page.getByLabel('Language to add').selectOption('pt-PT');
  await page.getByRole('button', { name: 'Add language' }).click();
  await page.goto(`${SITE}/admin/pages`);
  await page.getByRole('link', { name: 'Opening hours' }).first().click();

  const translations = page.getByRole('table', { name: 'The page in each language of the site' });
  await expect(translations.getByRole('row', { name: /European Portuguese/ })).toContainText('Not written yet');
  await expectNoViolations(page);
  await translations.getByRole('button', { name: 'Translate into European Portuguese from British English' }).focus();
  await page.keyboard.press('Enter');

  await expect(page.getByRole('status')).toHaveText('Started the European Portuguese translation as a copy of the British English page. Translate the text, then publish the translation.');
  await expect(page).toHaveURL(/\?locale=pt-PT$/);
  await expect(translations.getByRole('row', { name: /European Portuguese/ })).toContainText('Draft translation');
  await expect(page.getByRole('button', { name: 'Publish this translation' })).toBeVisible();
  await expectNoViolations(page);
});

test('the admin panel changes language from its header, by keyboard', async ({ page }) => {
  await signIn(page, SITE, 'ada@example.org', '/admin/pages');
  const language = page.getByLabel('Language', { exact: true });
  await expect(language.locator('option[value="es"]')).toHaveText('Español');
  await expect(language.locator('option[value="es"]')).toHaveAttribute('lang', 'es');
  await language.selectOption('es');
  await page.getByRole('button', { name: 'Change language' }).focus();
  await page.keyboard.press('Enter');

  await expect(page).toHaveURL(`${SITE}/admin/pages`);
  await expect(page.locator('html')).toHaveAttribute('lang', 'es');
  await expect(page.getByRole('status')).toHaveText('La administración está ahora en Español.');
  await expect(page).toHaveTitle('Páginas - Administración - Riverside Library');
  await expectNoViolations(page);

  await page.getByLabel('Idioma', { exact: true }).selectOption('en-GB');
  await page.getByRole('button', { name: 'Cambiar de idioma' }).click();
  await expect(page.getByRole('status')).toHaveText('The admin panel is now in British English.');
});

test('the languages screens reflow at 320px', async ({ page }) => {
  await page.setViewportSize({ width: 320, height: 640 });
  await signIn(page, SITE, 'ada@example.org', '/admin/settings/languages');
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
});
