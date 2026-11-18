// Accessibility tests: Playwright drives the browser and axe-core checks every page against WCAG 2.2 AA.
// They need a running skeleton: `docker compose up` in ../skeleton, then `composer a11y` here.
// SIGHTPRESS_BASE_URL changes the address (default http://localhost:8080).
// The component gallery (tests/A11y/gallery) and real sites for the sign-in pages (tests/A11y/sign-in), the content
// blocks (tests/A11y/blocks), the admin panel (tests/A11y/admin) and the demo site the crawl checks (tests/A11y/demo,
// made with site:create --with-demo-content; a second copy runs the journeys of journeys.spec.ts) run on PHP's
// built-in web server; Playwright starts them.
import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './tests/A11y',
  forbidOnly: !!process.env.CI,
  retries: 0,
  reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
  use: {
    baseURL: process.env.SIGHTPRESS_BASE_URL ?? 'http://localhost:8080',
    trace: 'retain-on-failure',
  },
  webServer: [
    {
      command: 'php -S 127.0.0.1:8099 tests/A11y/gallery/index.php',
      url: 'http://127.0.0.1:8099/form',
      reuseExistingServer: !process.env.CI,
      stdout: 'ignore',
      stderr: 'ignore',
    },
    {
      command: 'php -S 127.0.0.1:8098 tests/A11y/sign-in/index.php',
      url: 'http://127.0.0.1:8098/login',
      reuseExistingServer: !process.env.CI,
      stdout: 'ignore',
      stderr: 'ignore',
    },
    {
      command: 'php -S 127.0.0.1:8097 tests/A11y/blocks/index.php',
      url: 'http://127.0.0.1:8097/visit',
      reuseExistingServer: !process.env.CI,
      stdout: 'ignore',
      stderr: 'ignore',
    },
    {
      command: 'php -S 127.0.0.1:8087 tests/A11y/demo/index.php',
      url: 'http://127.0.0.1:8087/',
      reuseExistingServer: !process.env.CI,
      stdout: 'ignore',
      stderr: 'ignore',
    },
    // A second copy of the demo site, with its test mailbox, for the journeys from start to finish (journeys.spec.ts).
    {
      command: 'SIGHTPRESS_A11Y_SITE=journeys php -S 127.0.0.1:8086 tests/A11y/demo/index.php',
      url: 'http://127.0.0.1:8086/',
      reuseExistingServer: !process.env.CI,
      stdout: 'ignore',
      stderr: 'ignore',
    },
    {
      command: 'php -S 127.0.0.1:8091 tests/A11y/starter/index.php',
      url: 'http://127.0.0.1:8091/',
      reuseExistingServer: !process.env.CI,
      stdout: 'ignore',
      stderr: 'ignore',
    },
    // A second copy of the starter site, also in Portuguese and Hebrew, for the translations (translations.spec.ts).
    {
      command: 'SIGHTPRESS_A11Y_SITE=translated php -S 127.0.0.1:8084 tests/A11y/starter/index.php',
      url: 'http://127.0.0.1:8084/',
      reuseExistingServer: !process.env.CI,
      stdout: 'ignore',
      stderr: 'ignore',
    },
    // One copy of the admin test site per spec file (tests/A11y/support/admin-site.ts), so specs never reset each
    // other's site.
    ...Object.entries({ admin: 8096, account: 8095, people: 8094, media: 8093, menus: 8092, pages: 8089, settings: 8088, languages: 8085, operations: 8083, revisions: 8082, schedule: 8081, signup: 8079 }).map(([copy, port]) => ({
      command: `SIGHTPRESS_A11Y_SITE=${copy} php -S 127.0.0.1:${port} tests/A11y/admin/index.php`,
      url: `http://127.0.0.1:${port}/login`,
      reuseExistingServer: !process.env.CI,
      stdout: 'ignore' as const,
      stderr: 'ignore' as const,
    })),
  ],
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  ],
});
