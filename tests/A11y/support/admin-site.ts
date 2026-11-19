// Helpers for the specs that use the admin test site (tests/A11y/admin/index.php). Each spec file has its own copy
// of the site on its own port (see playwright.config.ts), so specs running side by side never reset each other.
import { expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

export const PASSWORD = 'Correct horse battery staple 7!';
const WCAG_22_AA = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

/** Ports of the copies of the admin test site, by spec. */
export const ADMIN_SITES = {
  admin: 'http://127.0.0.1:8096',
  account: 'http://127.0.0.1:8095',
  people: 'http://127.0.0.1:8094',
  media: 'http://127.0.0.1:8093',
  menus: 'http://127.0.0.1:8092',
  pages: 'http://127.0.0.1:8089',
  settings: 'http://127.0.0.1:8088',
  languages: 'http://127.0.0.1:8085',
  operations: 'http://127.0.0.1:8083',
  revisions: 'http://127.0.0.1:8082',
  schedule: 'http://127.0.0.1:8081',
  signup: 'http://127.0.0.1:8079',
  visibility: 'http://127.0.0.1:8078',
  menuentries: 'http://127.0.0.1:8077',
  profile: 'http://127.0.0.1:8076',
  features: 'http://127.0.0.1:8075',
} as const;

export async function expectNoViolations(page: Page): Promise<void> {
  // After a keyboard submit Playwright doesn't wait for the next page's stylesheets; check the page as people see it.
  await page.waitForLoadState('load');
  for (const colorScheme of ['light', 'dark'] as const) {
    await page.emulateMedia({ colorScheme });
    const results = await new AxeBuilder({ page }).withTags(WCAG_22_AA).analyze();
    expect(results.violations, `${colorScheme} mode`).toEqual([]);
  }
}

export async function signIn(page: Page, site: string, email: string, path = '/admin'): Promise<void> {
  await page.goto(`${site}/login?return=${encodeURIComponent(path)}`);
  await page.getByLabel('Email address').fill(email);
  await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page).toHaveURL(`${site}${path}`);
}
