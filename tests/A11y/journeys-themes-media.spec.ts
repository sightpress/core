// Themes and media from start to finish (Q-2), on a copy of the demo site (tests/A11y/demo, `mediajourney`):
// - an operator installs a theme, `theme:list` shows it, an admin applies it, a low-contrast colour is refused, a good
//   one is saved and reset; when the package is removed, the site falls back to the default theme and says so;
// - a 300 MB file is sent in parts through the temp folder, with a reload in the middle;
// - a folder is imported from the command line, and a file that isn't what its name says is refused;
// - a video gets captions in two languages;
// - the site moves to MinIO and its files are still served;
// - a 1 GB file goes straight from the browser to the bucket, with a reload in the middle.
import { createHash } from 'node:crypto';
import { createReadStream, createWriteStream, mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { once } from 'node:events';
import { expect, test, type APIRequestContext, type Page } from '@playwright/test';
import { expectNoViolations } from './support/admin-site';

const SITE = 'http://127.0.0.1:8062';
const BUCKET = 'http://127.0.0.1:9000';
const PASSWORD = 'Correct horse battery staple 7!';
const MEGABYTE = 1024 * 1024;

test.describe.configure({ mode: 'serial' });

let folder = '';

test.beforeAll(async ({ request }) => {
  test.setTimeout(180_000);
  folder = mkdtempSync(path.join(tmpdir(), 'sp-journey-'));
  await request.get(`${SITE}/_test/reset`);
  expect((await request.get(`${SITE}/`, { timeout: 170_000 })).status()).toBe(200);
});

test.afterAll(() => {
  rmSync(folder, { recursive: true, force: true });
});

async function signIn(page: Page, to: string): Promise<void> {
  await page.goto(`${SITE}/login?return=${encodeURIComponent(to)}`);
  await page.getByLabel('Email address').fill('ada@example.org');
  await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page).toHaveURL(`${SITE}${to}`);
}

async function operator(request: APIRequestContext, action: string): Promise<{ status: number; output: string }> {
  const response = await request.get(`${SITE}/_test/journey/${action}`, { timeout: 120_000 });
  expect(response.status(), action).toBe(200);

  return (await response.json()) as { status: number; output: string };
}

async function library(request: APIRequestContext, name: string): Promise<{ id: number; checksum: string } | null> {
  return (await (await request.get(`${SITE}/_test/journey/media?name=${encodeURIComponent(name)}`)).json()) as { id: number; checksum: string } | null;
}

/** Plain text of this size on disk, with its SHA-256. */
async function textFile(name: string, megabytes: number): Promise<{ file: string; sha256: string }> {
  const file = path.join(folder, name);
  const out = createWriteStream(file);
  const line = `${name} is a test file for large uploads.\n`;
  const block = Buffer.from(line.repeat(Math.ceil(MEGABYTE / line.length))).subarray(0, MEGABYTE);
  for (let written = 0; written < megabytes; written++) {
    if (!out.write(block)) {
      await once(out, 'drain');
    }
  }
  out.end();
  await once(out, 'finish');
  const hash = createHash('sha256');
  for await (const chunk of createReadStream(file)) {
    hash.update(chunk as Buffer);
  }

  return { file, sha256: hash.digest('hex') };
}

async function upload(page: Page, file: string): Promise<void> {
  // Signing in ends on the media page without waiting for it to load; clicking before media-upload.js has bound
  // its submit handler sends the whole file as a plain form, which the server refuses (413).
  await page.waitForLoadState('load');
  await page.getByLabel('File to upload').setInputFiles(file);
  await page.getByRole('button', { name: 'Upload', exact: true }).click();
}

test('a theme is installed, applied, recoloured, reset and removed', async ({ page, request }) => {
  test.setTimeout(180_000);
  await operator(request, 'install-theme');
  expect((await operator(request, 'theme-list')).output).toContain('acme-harbour');

  await signIn(page, '/admin/appearance');
  await page.getByRole('button', { name: 'Use Harbour' }).click();
  // Harbour has no landing or full-width layout, so the admin is told which pages change before confirming.
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Use Harbour?');
  await expect(page.getByRole('table', { name: 'Pages shown in the standard layout with Harbour' })).toContainText('A landing page');
  await expectNoViolations(page);
  await page.getByRole('button', { name: 'Use Harbour' }).click();
  await expect(page.getByRole('status')).toHaveText('Harbour is now the site’s theme.');
  await expectNoViolations(page);

  await page.getByRole('link', { name: 'Change the colours of the site’s theme' }).click();
  const primary = page.getByRole('group', { name: 'Light mode' }).getByLabel('Buttons and highlights');
  await primary.fill('#a0c4ff');
  await primary.press('Enter');
  await expect(page.getByText(/on-primary on primary has a contrast of \d\.\d\d:1 in light mode/).first()).toBeVisible();
  await expect(primary).toHaveValue('#a0c4ff');
  await primary.fill('#004080');
  await primary.press('Enter');
  await expect(page.getByRole('status')).toHaveText('The colours are saved.');
  await page.getByRole('button', { name: 'Use the theme’s own colours' }).click();
  await expect(page.getByText('The site uses the theme’s own colours.')).toBeVisible();

  await operator(request, 'remove-theme');
  expect((await page.goto(`${SITE}/`))?.status()).toBe(200);
  await page.goto(`${SITE}/admin/appearance`);
  await expect(page.getByRole('main')).toContainText('acme-harbour');
  await expect(page.locator('section', { has: page.getByRole('heading', { level: 2, name: 'SightPress Default' }) }).getByText('Status: The site’s theme')).toBeVisible();
  await expectNoViolations(page);
});

test('300 MB through the temp folder survives a reload', async ({ page, request }) => {
  test.setTimeout(420_000);
  const { file, sha256 } = await textFile('temp-folder.txt', 300);
  await signIn(page, '/admin/media');
  const offsets: number[] = [];
  page.on('request', (sent) => {
    const offset = sent.headers()['upload-offset'];
    if (sent.method() === 'PATCH' && offset !== undefined) {
      offsets.push(Number(offset));
    }
  });
  await upload(page, file);
  await expect.poll(() => offsets.length, { timeout: 120_000 }).toBeGreaterThan(2);
  await page.reload();
  const before = offsets.length;
  await upload(page, file);

  await expect(page).toHaveURL(new RegExp(`${SITE}/admin/media/\\d+$`), { timeout: 300_000 });
  expect(offsets[before], 'went on from where it stopped').toBeGreaterThan(0);
  expect((await library(request, 'temp-folder.txt'))?.checksum).toBe(sha256);
});

test('a folder is imported from the command line, and a fake file is refused', async ({ request }) => {
  const { status, output } = await operator(request, 'import');
  expect(status, 'a refused file makes the command fail').not.toBe(0);
  expect(output).toContain('first.txt');
  expect(output).toContain('second.txt');
  expect(output).toMatch(/Refused: .*fake\.pdf/);
  expect(await library(request, 'first.txt')).not.toBeNull();
  expect(await library(request, 'second.txt')).not.toBeNull();
  expect(await library(request, 'fake.pdf')).toBeNull();
  expect((await operator(request, 'import')).output).toContain('Already present');
});

test('a video gets captions in two languages', async ({ page, request }) => {
  const video = await library(request, 'tour.mp4');
  await signIn(page, `/admin/media/${video?.id}`);
  for (const [language, words] of [['British English', 'A gentle tone.'], ['European Portuguese', 'Um som suave.']]) {
    await page.getByLabel(`Captions file in ${language}`).setInputFiles({ name: `${language}.vtt`.replace(' ', '-'), mimeType: 'text/vtt', buffer: Buffer.from(`WEBVTT\n\n00:00.000 --> 00:02.000\n${words}\n`) });
    await page.getByRole('button', { name: `Upload captions in ${language}` }).click();
    await expect(page.getByRole('status')).toHaveText('The captions have been saved.');
  }
  await expectNoViolations(page);
  await page.goto(`${SITE}/every-block`);
  // The plain video block; the sign language video on the same page uses the demo video too.
  const player = page.locator('.block-video:not(.block-sign-language-video) video');
  await expect(player.locator('track[srclang="pt-PT"]')).toHaveCount(1);
  await expect(player.locator('track[srclang="en-GB"]')).toHaveCount(1);
});

test('the site moves to a bucket and its files are still served', async ({ page, request }) => {
  test.setTimeout(180_000);
  await operator(request, 'bucket');
  await page.goto(`${SITE}/every-block`);
  const picture = page.locator('.block-image img').first();
  await expect.poll(() => picture.evaluate((image: HTMLImageElement) => image.complete && image.naturalWidth > 0)).toBe(true);
  const src = (await picture.getAttribute('src')) ?? '';
  expect((await request.get(new URL(src, SITE).href)).status()).toBe(200);
  await expectNoViolations(page);
});

test('1 GB goes straight to the bucket and survives a reload', async ({ page, request }) => {
  test.setTimeout(900_000);
  const { file, sha256 } = await textFile('direct.txt', 1024);
  await signIn(page, '/admin/media');
  let toBucket = 0;
  let toSite = 0;
  page.on('request', (sent) => {
    if (sent.method() === 'PUT' && sent.url().startsWith(BUCKET)) {
      toBucket++;
    }
    if (sent.method() === 'PATCH' && sent.url().startsWith(SITE)) {
      toSite++;
    }
  });
  await upload(page, file);
  await expect.poll(() => toBucket, { timeout: 180_000 }).toBeGreaterThan(3);
  await page.reload();
  const before = toBucket;
  await upload(page, file);
  await expect.poll(() => toBucket, { timeout: 180_000 }).toBeGreaterThan(before);

  // The scheduler checks the file once it has arrived.
  const deadline = Date.now() + 600_000;
  while (!/\/admin\/media\/\d+$/.test(page.url()) && Date.now() < deadline) {
    await request.get(`${SITE}/_test/schedule`, { timeout: 300_000 });
    await page.waitForTimeout(2_000);
  }
  await expect(page).toHaveURL(new RegExp(`${SITE}/admin/media/\\d+$`));
  expect(toSite, 'no part went through the site').toBe(0);
  expect((await library(request, 'direct.txt'))?.checksum).toBe(sha256);
});
