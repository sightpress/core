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
    // A copy of the demo site for site search (search.spec.ts), so its rate limit never touches the other specs.
    {
      command: 'SIGHTPRESS_A11Y_SITE=search php -S 127.0.0.1:8074 tests/A11y/demo/index.php',
      url: 'http://127.0.0.1:8074/',
      reuseExistingServer: !process.env.CI,
      stdout: 'ignore',
      stderr: 'ignore',
    },
    // A members-only copy of the demo site, for the crawl (crawl.spec.ts): nothing is reachable or indexable signed out.
    {
      command: 'SIGHTPRESS_A11Y_SITE=members php -S 127.0.0.1:8073 tests/A11y/demo/index.php',
      url: 'http://127.0.0.1:8073/robots.txt',
      reuseExistingServer: !process.env.CI,
      stdout: 'ignore',
      stderr: 'ignore',
    },
    // Demo copies for the M3 crawl (crawl-themes-storage.spec.ts): one on a second theme, one with its files in the
    // skeleton's MinIO and a CDN address.
    ...Object.entries({ themed: 8064, cloud: 8063 }).map(([copy, port]) => ({
      command: `SIGHTPRESS_A11Y_SITE=${copy} php -S 127.0.0.1:${port} tests/A11y/demo/index.php`,
      url: `http://127.0.0.1:${port}/robots.txt`,
      reuseExistingServer: !process.env.CI,
      timeout: 180_000,
      stdout: 'ignore' as const,
      stderr: 'ignore' as const,
    })),
    // The themes and media journeys (journeys-themes-media.spec.ts). Several workers, because the page keeps asking
    // about an upload while the scheduler checks it; no opcode cache, because the journey rewrites the site registry.
    {
      command: 'SIGHTPRESS_A11Y_SITE=mediajourney PHP_CLI_SERVER_WORKERS=4 php -d opcache.enable_cli=0 -d opcache.enable=0 -S 127.0.0.1:8062 tests/A11y/demo/index.php',
      url: 'http://127.0.0.1:8062/robots.txt',
      reuseExistingServer: !process.env.CI,
      timeout: 180_000,
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
    ...Object.entries({ admin: 8096, account: 8095, people: 8094, media: 8093, menus: 8092, pages: 8089, settings: 8088, languages: 8085, operations: 8083, revisions: 8082, schedule: 8081, signup: 8079, visibility: 8078, menuentries: 8077, profile: 8076, features: 8075, analytics: 8072, assistant: 8071, translate: 8070, appearance: 8069, limits: 8068, provisioning: 8067, chunked: 8066, captions: 8065, roles: 8061 }).map(([copy, port]) => ({
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
