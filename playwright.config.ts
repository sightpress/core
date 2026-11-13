// Accessibility tests: Playwright drives the browser and axe-core checks every page against WCAG 2.2 AA.
// They need a running skeleton: `docker compose up` in ../skeleton, then `composer a11y` here.
// SIGHTPRESS_BASE_URL changes the address (default http://localhost:8080).
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
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  ],
});
