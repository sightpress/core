// Core's form building blocks (form/macros.html.twig) in the component gallery: labels, hints, errors linked to
// their fields, the error summary that gets focus, and everything by keyboard.
import { expect, test, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const FORM = 'http://127.0.0.1:8099/form';
const WCAG_22_AA = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

async function expectNoViolations(page: Page): Promise<void> {
  for (const colorScheme of ['light', 'dark'] as const) {
    await page.emulateMedia({ colorScheme });
    const results = await new AxeBuilder({ page }).withTags(WCAG_22_AA).analyze();
    expect(results.violations, `${colorScheme} mode`).toEqual([]);
  }
}

async function submitEmpty(page: Page): Promise<void> {
  await page.goto(FORM);
  await page.getByLabel('Full name').focus();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('heading', { level: 2, name: 'There is a problem' })).toBeVisible();
}

test('every control has a label, optional ones say so, and hints are read with the field', async ({ page }) => {
  await page.goto(FORM);

  await expect(page.getByLabel('Email address')).toHaveAccessibleDescription('We only use it to sign you in.');
  await expect(page.getByLabel('Biography (optional)')).toBeVisible();
  await expect(page.getByRole('combobox', { name: 'Role' })).toBeVisible();
  await expect(page.getByRole('group', { name: 'How should we contact you?' }).getByRole('radio')).toHaveCount(2);
  await expect(page.getByRole('group', { name: 'Topics you write about (optional)' }).getByRole('checkbox')).toHaveCount(2);
  await expect(page.getByRole('checkbox', { name: 'I agree to the terms of use' })).toBeVisible();
  await expectNoViolations(page);
});

test('after a failed submit the error summary has focus and the title says there is an error', async ({ page }) => {
  await submitEmpty(page);

  await expect(page).toHaveTitle(/^Error: Your profile/);
  await expect(page.locator('#profile-errors')).toBeFocused();
  await expect(page.locator('#profile-errors').getByRole('link')).toHaveText([
    'Enter full name',
    'Enter email address',
    'Select role',
    'Select how should we contact you?',
    'Agree to the terms of use to continue',
  ]);
  await expectNoViolations(page);
});

test('each error is linked to its field', async ({ page }) => {
  await submitEmpty(page);

  const email = page.getByLabel('Email address');
  await expect(email).toHaveAttribute('aria-invalid', 'true');
  await expect(email).toHaveAccessibleDescription('We only use it to sign you in. Error: Enter email address');
  await expect(page.getByRole('group', { name: 'How should we contact you?' }))
    .toHaveAccessibleDescription('Error: Select how should we contact you?');
  await expect(page.getByLabel('Biography (optional)')).not.toHaveAttribute('aria-invalid', 'true');
});

test('by keyboard: the summary links move focus to the field', async ({ page }) => {
  await submitEmpty(page);

  await page.keyboard.press('Tab');
  await expect(page.getByRole('link', { name: 'Enter full name' })).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(page.getByRole('link', { name: 'Enter email address' })).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(page.getByLabel('Email address')).toBeFocused();
});

test('by keyboard: fill in the form and send it', async ({ page }) => {
  await submitEmpty(page);

  await page.getByLabel('Full name').focus();
  await page.keyboard.type('Ada Lovelace');
  await page.keyboard.press('Tab');
  await expect(page.getByLabel('Email address')).toBeFocused();
  await page.keyboard.type('ada@example.org');
  await page.keyboard.press('Tab');
  await page.keyboard.type('Writes about engines.');
  await page.keyboard.press('Tab');
  await expect(page.getByRole('combobox', { name: 'Role' })).toBeFocused();
  await page.getByRole('combobox', { name: 'Role' }).selectOption('editor');
  await page.keyboard.press('Tab');
  await expect(page.getByRole('radio', { name: 'By email' })).toBeFocused();
  await page.keyboard.press('Space');
  await page.keyboard.press('Tab');
  await expect(page.getByRole('checkbox', { name: 'Accessibility' })).toBeFocused();
  await page.keyboard.press('Space');
  await page.keyboard.press('Tab');
  await page.keyboard.press('Tab');
  await expect(page.getByRole('checkbox', { name: 'I agree to the terms of use' })).toBeFocused();
  await page.keyboard.press('Space');
  await page.keyboard.press('Tab');
  await expect(page.getByRole('button', { name: 'Save profile' })).toBeFocused();
  await page.keyboard.press('Enter');

  await expect(page.getByRole('heading', { level: 1, name: 'Profile saved' })).toBeVisible();
  await expect(page.getByText('Thank you, Ada Lovelace.')).toBeVisible();
  await expectNoViolations(page);
});

test('values are kept after a failed submit', async ({ page }) => {
  await page.goto(FORM);
  await page.getByLabel('Full name').fill('Ada');
  await page.getByLabel('Email address').fill('not-an-email');
  await page.getByRole('button', { name: 'Save profile' }).click();

  await expect(page.getByLabel('Full name')).toHaveValue('Ada');
  await expect(page.getByLabel('Email address')).toHaveValue('not-an-email');
  await expect(page.locator('#profile-errors').getByRole('link').first())
    .toHaveText('Enter an email address in the right format, like name@example.com');
});
