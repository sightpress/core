// Accessibility tests: Playwright drives the browser and axe-core checks every page against WCAG 2.2 AA.
// They need a running skeleton: `docker compose up` in ../skeleton, then `composer a11y` here.
// SIGHTPRESS_BASE_URL changes the address (default http://localhost:8080).
// The component gallery (tests/A11y/gallery) runs on PHP's built-in web server; Playwright starts it.
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
  webServer: {
    command: 'php -S 127.0.0.1:8099 tests/A11y/gallery/index.php',
    url: 'http://127.0.0.1:8099/form',
    reuseExistingServer: !process.env.CI,
    stdout: 'ignore',
    stderr: 'ignore',
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  ],
});
