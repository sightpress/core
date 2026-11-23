// Large files in the media library (M-7): sent in parts by keyboard, with pause, resume and cancel, progress announced
// in the polite live region, resumed after a reload, and refused for their real type once they have arrived. Without
// JavaScript the form still uploads a small file. No axe violations in light and dark mode.
import { expect, test, type Page } from '@playwright/test';
import { ADMIN_SITES, expectNoViolations, signIn } from './support/admin-site';

const SITE = ADMIN_SITES.chunked;
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64');

// Plain text, larger than the test server's part size, different per test so it is never "already in the library".
const text = (seed: string, megabytes = 5): Buffer => Buffer.from(`${seed}\n${'SightPress large upload test line.\n'.repeat(Math.ceil((megabytes * 1024 * 1024) / 35))}`);

test.beforeEach(async ({ request }) => {
  await request.get(`${SITE}/_test/reset`);
});

async function choose(page: Page, name: string, buffer: Buffer, mimeType = 'text/plain'): Promise<void> {
  await page.getByLabel('File to upload').setInputFiles({ name, mimeType, buffer });
}

async function slowParts(page: Page, ms = 400, gate: Promise<void> = Promise.resolve()): Promise<void> {
  await page.route('**/admin/media/uploads/*', async (route) => {
    if (route.request().method() === 'PATCH') {
      await gate;
      await new Promise((resolve) => setTimeout(resolve, ms));
    }
    await route.continue().catch(() => undefined);
  });
}

test('a large file is uploaded by keyboard, with pause and resume announced', async ({ page }) => {
  await signIn(page, SITE, 'cleo@example.org', '/admin/media');
  await expectNoViolations(page);
  // Parts wait here until the test lets them go, so the upload can't finish while axe scans the page.
  let release: () => void = () => undefined;
  await slowParts(page, 400, new Promise<void>((resolve) => { release = resolve; }));
  const seen: string[] = [];
  await page.exposeFunction('reportStatus', (value: string) => { seen.push(value); });
  await page.evaluate(() => {
    const status = document.getElementById('media-upload-status') as HTMLElement;
    new MutationObserver(() => (window as unknown as { reportStatus(v: string): void }).reportStatus(status.textContent ?? '')).observe(status, { childList: true, characterData: true, subtree: true });
  });

  await choose(page, 'notes.txt', text('keyboard'));
  await page.getByRole('button', { name: 'Upload', exact: true }).focus();
  await page.keyboard.press('Enter');

  const pause = page.getByRole('button', { name: 'Pause' });
  await expect(pause).toBeVisible();
  await expect(page.getByRole('progressbar')).toBeVisible();
  await expect(page.locator('#media-upload-status')).toHaveAttribute('aria-live', 'polite');
  await expectNoViolations(page);
  await pause.focus();
  await page.keyboard.press('Enter');

  const resume = page.getByRole('button', { name: 'Resume' });
  await expect(resume).toBeFocused();
  await expect(page.locator('#media-upload-status')).toHaveText(/^Upload of notes\.txt paused at \d+%\.$/);
  await expectNoViolations(page);
  release();
  await page.keyboard.press('Enter');
  await expect(pause).toBeFocused();

  await expect(page).toHaveURL(new RegExp(`${SITE}/admin/media/\\d+$`), { timeout: 60_000 });
  await expect(page.getByRole('status')).toHaveText('notes.txt has been uploaded. Describe it below, so everyone can understand it.');
  expect(seen.some((line) => /^Uploading notes\.txt: \d+0%$/.test(line))).toBe(true);
  expect(seen.some((line) => /^Resuming notes\.txt from \d+%\.$/.test(line))).toBe(true);
});

test('an upload goes on from where it stopped after a reload', async ({ page }) => {
  await signIn(page, SITE, 'cleo@example.org', '/admin/media');
  await slowParts(page, 700);
  const file = text('reload');
  const offsets: number[] = [];
  page.on('request', (request) => {
    const offset = request.headers()['upload-offset'];
    if (request.method() === 'PATCH' && offset !== undefined) {
      offsets.push(Number(offset));
    }
  });
  const lastModified = 1_780_000_000_000;
  const start = async (): Promise<void> => {
    await page.evaluate(({ bytes, modified }) => {
      const input = document.querySelector('input[type="file"]') as HTMLInputElement;
      const transfer = new DataTransfer();
      transfer.items.add(new File([Uint8Array.from(atob(bytes), (c) => c.charCodeAt(0))], 'reload.txt', { type: 'text/plain', lastModified: modified }));
      input.files = transfer.files;
    }, { bytes: file.toString('base64'), modified: lastModified });
    await page.getByRole('button', { name: 'Upload', exact: true }).click();
  };
  await start();
  await expect.poll(() => offsets.length, { timeout: 20_000 }).toBeGreaterThan(1);

  await page.reload();
  const before = offsets.length;
  await start();

  await expect(page).toHaveURL(new RegExp(`${SITE}/admin/media/\\d+$`), { timeout: 60_000 });
  expect(offsets[before]).toBeGreaterThan(0);
});

test('cancelling an upload says so and gives the form back', async ({ page }) => {
  await signIn(page, SITE, 'cleo@example.org', '/admin/media');
  await slowParts(page, 800);
  await choose(page, 'cancel.txt', text('cancel'));
  await page.getByRole('button', { name: 'Upload', exact: true }).click();

  const cancel = page.getByRole('button', { name: 'Cancel upload' });
  await expect(cancel).toBeVisible();
  await cancel.focus();
  await page.keyboard.press('Enter');

  await expect(page.locator('#media-upload-status')).toHaveText('Upload of cancel.txt cancelled.');
  await expect(cancel).toBeHidden();
  await expect(page.getByRole('button', { name: 'Upload', exact: true })).toBeFocused();
  await expectNoViolations(page);
});

test('a file that is not what its name says is refused after the last part', async ({ page }) => {
  await signIn(page, SITE, 'cleo@example.org', '/admin/media');
  const html = Buffer.from(`<!doctype html><html><body><script>alert(1)</script>${'<p>padding</p>\n'.repeat(350_000)}</body></html>`);
  await choose(page, 'holiday.pdf', html, 'application/pdf');
  await page.getByRole('button', { name: 'Upload', exact: true }).click();

  await expect(page.locator('#upload-errors')).toBeFocused({ timeout: 60_000 });
  await expect(page.locator('#upload-errors').getByRole('link')).toHaveText(/^This type of file can't be uploaded\./);
  await expect(page).toHaveURL(`${SITE}/admin/media`);
  await expectNoViolations(page);
});

test.describe('without JavaScript', () => {
  test.use({ javaScriptEnabled: false });

  test('the form still uploads a small file', async ({ page }) => {
    await signIn(page, SITE, 'cleo@example.org', '/admin/media');
    await choose(page, 'dot.png', PNG, 'image/png');
    await page.getByRole('button', { name: 'Upload', exact: true }).click();

    await expect(page).toHaveURL(new RegExp(`${SITE}/admin/media/\\d+$`));
    await expect(page.getByRole('status')).toContainText('dot.png has been uploaded.');
  });
});
