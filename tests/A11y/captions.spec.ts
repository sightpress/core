// Captions and transcripts (M-4): captions per language on a video's page in the media library, a bad file refused
// with its line number, one <track> per language in the player, the transcript disclosure by keyboard, the assistant's
// tidied transcript needing a person's check, and the transcript in the Markdown version. No axe violations.
import { expect, test } from '@playwright/test';
import { ADMIN_SITES, expectNoViolations, signIn } from './support/admin-site';

const SITE = ADMIN_SITES.captions;

let ids: { video: number; audio: number };

test.beforeEach(async ({ request }) => {
  await request.get(`${SITE}/_test/reset`);
  await request.get(`${SITE}/_test/assistant`);
  ids = await (await request.get(`${SITE}/_test/captioned`)).json();
});

test('an admin replaces captions by keyboard, and a bad file is refused with its line number', async ({ page }) => {
  await signIn(page, SITE, 'ada@example.org', `/admin/media/${ids.video}`);
  await expect(page.getByRole('heading', { level: 2, name: 'Captions' })).toBeVisible();
  await expectNoViolations(page);

  const file = page.getByLabel('Captions file in British English');
  await file.setInputFiles({ name: 'bad.vtt', mimeType: 'text/vtt', buffer: Buffer.from('WEBVTT\n\n00:05.000 --> 00:01.000\nHello\n') });
  await page.getByRole('button', { name: 'Upload captions in British English' }).press('Enter');
  await expect(page.getByText('This captions file has a mistake on line 3: the cue must end after it starts.')).toBeVisible();
  await expect(page.locator('.admin-panel .error-summary')).toBeFocused();
  await expectNoViolations(page);

  await page.getByLabel('Captions file in British English').setInputFiles({ name: 'new.vtt', mimeType: 'text/vtt', buffer: Buffer.from('WEBVTT\n\n00:00.000 --> 00:02.000\nNew words\n') });
  await page.getByRole('button', { name: 'Upload captions in British English' }).press('Enter');
  await expect(page.getByRole('status')).toHaveText('The captions have been saved.');
  await expect(page.getByRole('link', { name: 'new.vtt' })).toBeVisible();

  await page.setViewportSize({ width: 320, height: 640 });
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
});

test('the player has a track per language and the transcript opens by keyboard', async ({ page, request }) => {
  await page.goto(`${SITE}/harbour-tour`);
  const tracks = page.locator('video track');
  await expect(tracks).toHaveCount(2);
  await expect(tracks.nth(0)).toHaveAttribute('srclang', 'en-GB');
  await expect(tracks.nth(0)).toHaveAttribute('default', '');
  await expect(tracks.nth(1)).toHaveAttribute('srclang', 'pt-PT');
  const src = (await tracks.nth(0).getAttribute('src')) ?? '';
  expect(src.startsWith('/media/')).toBe(true);
  const captions = await request.get(`${SITE}${src}`);
  expect(captions.headers()['content-type']).toContain('text/vtt');
  await expect.poll(() => page.evaluate(() => document.querySelector('video')?.textTracks[0]?.mode)).toBe('showing');

  const summary = page.locator('.block-audio summary');
  await summary.focus();
  await expect(page.getByText('hello and welcome to the library thank you')).toBeHidden();
  await page.keyboard.press('Enter');
  await expect(page.getByText('hello and welcome to the library thank you')).toBeVisible();
  await expectNoViolations(page);

  const markdown = await (await request.get(`${SITE}/harbour-tour.md`)).text();
  expect(markdown).toContain('hello and welcome to the library thank you');
  const full = await (await request.get(`${SITE}/llms-full.txt`)).text();
  expect(full).toContain('A gentle tone plays over coloured test patterns.');
});

test('a transcript the assistant tidied waits for a person to check it', async ({ page }) => {
  await signIn(page, SITE, 'ada@example.org', `/admin/media/${ids.audio}`);
  await page.waitForLoadState('load');
  await page.getByRole('button', { name: /^Tidy up the transcript/ }).first().click();
  await page.getByRole('button', { name: 'Insert' }).click();
  await expect(page.locator('#details-transcript-0')).toHaveValue(/Speaker 1: Hello, and welcome/);
  await expect(page.locator('#details-transcript-0')).toBeFocused();
  await page.getByRole('button', { name: 'Save details' }).click();
  await expect(page.getByLabel(/I checked the transcript/).first()).toBeVisible();
  await expectNoViolations(page);

  await page.getByLabel(/I checked the transcript/).first().check();
  await page.getByRole('button', { name: 'Save details' }).click();
  await expect(page.getByLabel(/I checked the transcript/)).toHaveCount(0);
});
