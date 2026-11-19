// Journeys from start to finish (C-8.2, Q-2) on a copy of the demo site (tests/A11y/demo, made with
// site:create --with-demo-content, in production mode, in British English and European Portuguese, sign-up open)
// whose test mailbox keeps every email the site sends:
// - a contributor writes a page and sends it for review, the editors are told by email, an editor approves and
//   publishes it, and only then can visitors read it, find it in the sitemap and llms.txt, and get its Markdown;
// - an admin invites someone, who types the six-digit code from their email, sets up their account and signs in;
// - someone who forgot their password asks for a code, types the one from their email and signs in with a new
//   password, while the old one stops working;
// - someone creates their own account with the code from their email, signs in as a member and reads a members page;
// - an editor translates a page and publishes the translation, which is then reachable under /pt-pt/ with alternates;
// - an editor moves a page, and its old address leads to the new one (301);
// - an editor schedules an approved page, and the next schedule:run publishes it;
// - an admin turns site search off, and /search answers "not found";
// - a member changes their email address with the code sent to the new one.
// No email with a code has a link in it. The screens on the way have no axe violations.
import { expect, test, type APIRequestContext, type Page } from '@playwright/test';
import { expectNoViolations } from './support/admin-site';

const SITE = 'http://127.0.0.1:8086';
const PASSWORD = 'Correct horse battery staple 7!';
// Bea chooses this one when she resets her password, in the third journey.
const BEA_PASSWORD = 'A completely new password 2!';

test.describe.configure({ mode: 'serial' });

test.beforeAll(async ({ request }) => {
  test.setTimeout(150_000);
  await request.get(`${SITE}/_test/reset`);
  expect((await request.get(`${SITE}/`, { timeout: 140_000 })).status()).toBe(200);
});

type Mail = { to: string[]; subject: string; text: string; html: string | null };

/** The latest email the site sent to this address. */
async function mailTo(request: APIRequestContext, address: string): Promise<Mail> {
  const response = await request.get(`${SITE}/_test/mail?to=${encodeURIComponent(address)}`);
  expect(response.status(), `an email to ${address}`).toBe(200);

  return (await response.json()) as Mail;
}

/** The six-digit code in an email that must not contain a link. */
function codeIn(mail: Mail): string {
  for (const body of [mail.text, mail.html ?? '']) {
    expect(body, `“${mail.subject}” has no link`).not.toMatch(/https?:\/\/|<a\s/i);
  }
  const code = mail.text.match(/\b(\d{3}) ?(\d{3})\b/);
  expect(code, `“${mail.subject}” has a six-digit code`).not.toBeNull();

  return `${code?.[1]}${code?.[2]}`;
}

async function signIn(page: Page, email: string, password = PASSWORD): Promise<void> {
  await page.goto(`${SITE}/login`);
  await page.getByLabel('Email address').fill(email);
  await page.getByLabel('Password', { exact: true }).fill(password);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await page.waitForLoadState('load');
}

async function signOut(page: Page): Promise<void> {
  await page.context().clearCookies();
}

test('a contributor’s page goes through review and is then public', async ({ page, request }) => {
  test.setTimeout(120_000);
  expect((await request.get(`${SITE}/book-club`)).status()).toBe(404);

  // Cleo, a contributor, starts a page, writes it and sends it for review.
  await signIn(page, 'cleo@example.org');
  await expect(page).toHaveURL(`${SITE}/admin`);
  await page.goto(`${SITE}/admin/pages/new`);
  await page.getByLabel('Title', { exact: true }).fill('Book club');
  await page.getByRole('button', { name: 'Start the page' }).click();
  await expect(page.getByRole('heading', { level: 1, name: 'Edit “Book club”' })).toBeVisible();
  await page.getByLabel('Description for search engines').fill('Meet other readers every first Tuesday of the month.');
  await page.getByLabel('Type of block to add').selectOption({ label: 'Text' });
  await page.getByRole('button', { name: 'Add block' }).click();
  await page.getByLabel('Text', { exact: true }).fill('<p>We meet every first <strong>Tuesday</strong> of the month.</p>');
  await page.getByRole('button', { name: 'Save page' }).first().click();
  await expect(page.getByRole('status')).toHaveText('Saved “Book club”.');
  await page.getByLabel('Note for the next person').fill('Ready for a look');
  await page.getByRole('button', { name: 'Submit for review' }).click();
  await expect(page.getByRole('status')).toHaveText('Sent for review. The editors have been told by email.');
  await expectNoViolations(page);
  expect((await request.get(`${SITE}/book-club`)).status(), 'not public while in review').toBe(404);

  // Bea, an editor, is told by email; the notification links to the page in the admin. (Emails link to https:// and
  // the site's first domain; this test site is served over plain HTTP on its own port, so only the path is followed.)
  const review = await mailTo(request, 'bea@example.org');
  expect(review.subject).toBe('Please review "Book club" on Riverside Library');
  const editorPath = review.text.match(/https:\/\/127\.0\.0\.1(\/admin\/pages\/\d+)/)?.[1];
  expect(editorPath, 'the email links to the page editor').toBeDefined();
  await signOut(page);

  // Bea opens the page from the email, approves it and publishes it.
  await signIn(page, 'bea@example.org');
  await page.goto(`${SITE}${editorPath}`);
  await expect(page.getByRole('heading', { level: 1, name: 'Edit “Book club”' })).toBeVisible();
  await page.getByRole('button', { name: 'Approve' }).click();
  await expect(page.getByRole('status')).toHaveText('Approved. It can be published now.');
  expect((await request.get(`${SITE}/book-club`)).status(), 'not public once only approved').toBe(404);
  await page.getByRole('button', { name: 'Publish' }).click();
  await expect(page.getByRole('status')).toHaveText('Published. Visitors can see the page now.');
  await expectNoViolations(page);
  await signOut(page);

  // A visitor reads it; search engines and AI readers find it.
  await page.goto(`${SITE}/book-club`);
  await expect(page.getByRole('heading', { level: 1, name: 'Book club' })).toBeVisible();
  await expect(page.getByRole('main').locator('strong')).toHaveText('Tuesday');
  await expect(page.locator('meta[name="description"]')).toHaveAttribute('content', 'Meet other readers every first Tuesday of the month.');
  await expectNoViolations(page);
  expect(await (await request.get(`${SITE}/sitemap-en-gb.xml`)).text()).toContain(`<loc>${SITE}/book-club</loc>`);
  expect(await (await request.get(`${SITE}/llms.txt`)).text()).toContain(`${SITE}/book-club.md`);
  const markdown = await request.get(`${SITE}/book-club.md`);
  expect(markdown.status()).toBe(200);
  expect(await markdown.text()).toContain('**Tuesday**');
});

test('an invited person accepts with the code from their email and signs in', async ({ page, request }) => {
  test.setTimeout(60_000);
  await signIn(page, 'ada@example.org');
  await page.goto(`${SITE}/admin/users/invite`);
  await expect(page.getByRole('main')).toContainText('We email them a 6-digit invitation code, never a link.');
  await expect(page.getByRole('main')).not.toContainText(/link works|the link/i);
  await page.getByLabel('Email address').fill('zoe@example.org');
  await page.getByRole('group', { name: 'Role' }).getByRole('radio', { name: /^Contributor/ }).check();
  await page.getByLabel('First name (optional)').fill('Zoë');
  await page.getByRole('button', { name: 'Send invitation' }).click();
  await expect(page.getByRole('status')).toHaveText('We have sent an invitation to zoe@example.org to join as contributor. Its code works for 7 days.');
  await page.goto(`${SITE}/admin/users`);
  await expect(page.getByRole('table', { name: 'Open invitations, newest first' }).getByRole('columnheader', { name: 'Code works until' })).toBeVisible();
  await signOut(page);

  const code = codeIn(await mailTo(request, 'zoe@example.org'));
  await page.goto(`${SITE}/login`);
  await page.getByRole('link', { name: 'Accept an invitation' }).click();
  await page.getByLabel('Email address').fill('zoe@example.org');
  await page.getByLabel('6-digit code').fill(code);
  await page.getByRole('button', { name: 'Continue' }).click();
  await expect(page.getByLabel('First name')).toHaveValue('Zoë');
  await page.getByLabel('Last name').fill('Ødegaard');
  await page.getByLabel('Password').fill('A long password for Zoë 5!');
  await page.getByRole('button', { name: 'Create my account' }).click();
  await expect(page.getByRole('status')).toHaveText('Your account is ready. Sign in with zoe@example.org and your new password.');

  await signIn(page, 'zoe@example.org', 'A long password for Zoë 5!');
  await expect(page).toHaveURL(`${SITE}/admin`);
  await expect(page.getByRole('navigation', { name: 'Admin' }).getByRole('link', { name: 'Pages' })).toBeVisible();
  await expect(page.getByRole('navigation', { name: 'Admin' }).getByRole('link', { name: 'People' })).toHaveCount(0);
  await expectNoViolations(page);
});

test('someone who forgot their password resets it with the code from their email', async ({ page, request }) => {
  test.setTimeout(60_000);
  await page.goto(`${SITE}/login`);
  await page.getByRole('link', { name: 'Forgot your password?' }).click();
  await page.getByLabel('Email address').fill('bea@example.org');
  await page.getByRole('button', { name: 'Send me a code' }).click();
  await expect(page.getByRole('heading', { level: 1, name: 'Enter your code' })).toBeVisible();

  const mail = await mailTo(request, 'bea@example.org');
  await page.getByLabel('6-digit code').fill(codeIn(mail));
  await page.getByRole('button', { name: 'Continue' }).click();
  await expect(page.getByRole('heading', { level: 1, name: 'Choose a new password' })).toBeVisible();
  await page.getByLabel('New password').fill('A completely new password 2!');
  await page.getByLabel('New password').press('Enter');
  await expect(page.getByRole('status')).toHaveText('Your password has been changed. Sign in with your new password.');

  await signIn(page, 'bea@example.org');
  await expect(page).toHaveURL(/\/login/);
  await signIn(page, 'bea@example.org', BEA_PASSWORD);
  await expect(page).toHaveURL(`${SITE}/admin`);
});

test('someone creates their own account with an emailed code and reads a page for members', async ({ page, request }) => {
  test.setTimeout(120_000);
  expect((await request.get(`${SITE}/for-members`, { maxRedirects: 0 })).status(), 'members only').toBe(302);

  await page.goto(`${SITE}/login`);
  await page.getByRole('link', { name: 'Create an account' }).click();
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Create an account on Riverside Library');
  await expectNoViolations(page);
  await page.getByLabel('First name').fill('Nia');
  await page.getByLabel('Last name').fill('Lind');
  await page.getByLabel('Email address').fill('nia@example.org');
  await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
  await page.getByRole('button', { name: 'Create my account' }).click();
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Confirm your email address');

  const code = codeIn(await mailTo(request, 'nia@example.org'));
  await page.getByLabel('6-digit code').fill(code);
  await page.getByRole('button', { name: 'Confirm my email address' }).click();
  await expect(page.getByRole('status')).toHaveText('Your email address is confirmed and your account is ready. Sign in with nia@example.org and your password.');

  await page.goto(`${SITE}/for-members`);
  await expect(page).toHaveURL(`${SITE}/login?return=%2Ffor-members`);
  await page.getByLabel('Email address').fill('nia@example.org');
  await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page).toHaveURL(`${SITE}/for-members`);
  await expect(page.getByRole('heading', { level: 1, name: 'For members' })).toBeVisible();
  await expectNoViolations(page);
  expect((await page.goto(`${SITE}/admin`))?.status(), 'a member never reaches the admin').toBe(403);
});

test('an editor translates a page and publishes it under /pt-pt/ with alternates', async ({ page, request }) => {
  test.setTimeout(120_000);
  await signIn(page, 'bea@example.org', BEA_PASSWORD);
  await expect(page).toHaveURL(`${SITE}/admin`);
  await page.goto(`${SITE}/admin/pages`);
  await page.getByRole('link', { name: 'Book club' }).first().click();
  const translations = page.getByRole('table', { name: 'The page in each language of the site' });
  await translations.getByRole('button', { name: 'Translate into European Portuguese from British English' }).click();
  await expect(page).toHaveURL(/\?locale=pt-PT$/);
  await page.getByLabel('Title', { exact: true }).fill('Clube de leitura');
  await page.getByLabel('Address (optional)').fill('clube-de-leitura');
  await page.getByLabel('Description for search engines').fill('Encontre outros leitores na primeira terça-feira de cada mês.');
  await page.getByLabel('Text', { exact: true }).fill('<p>Encontramo-nos na primeira <strong>terça-feira</strong> de cada mês.</p>');
  await page.getByRole('button', { name: 'Save page' }).first().click();
  await expect(page.getByRole('status')).toContainText('Saved “Clube de leitura”.');
  await page.getByRole('button', { name: 'Publish this translation' }).click();
  await expect(page.getByRole('status')).toHaveText('Published the European Portuguese translation. Visitors can see it now.');
  await expectNoViolations(page);
  await signOut(page);

  await page.goto(`${SITE}/pt-pt/clube-de-leitura`);
  await expect(page.locator('html')).toHaveAttribute('lang', 'pt-PT');
  await expect(page.getByRole('heading', { level: 1, name: 'Clube de leitura' })).toBeVisible();
  const alternates = await page.locator('head link[rel="alternate"][hreflang]:not([type])').evaluateAll((links) => links.map((link) => [link.getAttribute('hreflang'), (link as HTMLLinkElement).href]));
  expect(Object.fromEntries(alternates)).toEqual({ 'en-GB': `${SITE}/book-club`, 'pt-PT': `${SITE}/pt-pt/clube-de-leitura`, 'x-default': `${SITE}/book-club` });
  await expectNoViolations(page);
  expect(await (await request.get(`${SITE}/sitemap-pt-pt.xml`)).text()).toContain(`<loc>${SITE}/pt-pt/clube-de-leitura</loc>`);
});

test('an editor moves a page and its old address leads to the new one', async ({ page, request }) => {
  await signIn(page, 'bea@example.org', BEA_PASSWORD);
  await expect(page).toHaveURL(`${SITE}/admin`);
  await page.goto(`${SITE}/admin/pages`);
  await page.getByRole('link', { name: 'Book club' }).first().click();
  const place = page.getByRole('region', { name: 'Place in the site' });
  await place.getByLabel('Move to').selectOption({ label: 'Under “About us” (/about)' });
  await place.getByRole('button', { name: 'Move the page' }).click();
  await expect(page.getByRole('status')).toHaveText('Moved the page. Its address is now /about/book-club; links to its old address still lead to it.');
  await signOut(page);

  const moved = await request.get(`${SITE}/book-club`, { maxRedirects: 0 });
  expect(moved.status()).toBe(301);
  expect(new URL(moved.headers()['location'], SITE).href).toBe(`${SITE}/about/book-club`);
  expect((await request.get(`${SITE}/about/book-club`)).status()).toBe(200);
  const translated = await request.get(`${SITE}/pt-pt/clube-de-leitura`, { maxRedirects: 0 });
  expect(translated.status(), 'the translation moved with it').toBe(301);
  expect(new URL(translated.headers()['location'], SITE).href).toBe(`${SITE}/pt-pt/sobre/clube-de-leitura`);
});

test('an approved page is published by the scheduler at its time', async ({ page, request }) => {
  test.setTimeout(240_000);
  await signIn(page, 'bea@example.org', BEA_PASSWORD);
  await expect(page).toHaveURL(`${SITE}/admin`);
  await page.goto(`${SITE}/admin/pages/new`);
  await page.getByLabel('Title', { exact: true }).fill('Winter concert');
  await page.getByRole('button', { name: 'Start the page' }).click();
  await page.getByLabel('Description for search engines').fill('Our winter concert, with the library choir.');
  await page.getByLabel('Type of block to add').selectOption({ label: 'Text' });
  await page.getByRole('button', { name: 'Add block' }).click();
  await page.getByLabel('Text', { exact: true }).fill('<p>The library choir sings.</p>');
  await page.getByRole('button', { name: 'Save page' }).first().click();
  await page.getByRole('button', { name: 'Submit for review' }).click();
  await page.getByRole('button', { name: 'Approve' }).click();
  await expect(page.getByRole('status')).toHaveText('Approved. It can be published now.');

  // A minute from now, in the editor's own time zone, which the form names.
  const schedule = page.getByRole('region', { name: 'Schedule' });
  const zone = /Times are in your time zone, ([^\s.]+)\./.exec(await schedule.getByText(/^Times are in your time zone/).innerText())?.[1] ?? 'UTC';
  const due = new Date(Date.now() + 60_000);
  const parts = Object.fromEntries(new Intl.DateTimeFormat('en-GB', { timeZone: zone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(due).map((part) => [part.type, part.value]));
  await schedule.getByLabel('Publish at (optional)').fill(`${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`);
  await schedule.getByRole('button', { name: 'Save the schedule' }).click();
  await expect(page.getByRole('status')).toContainText('Scheduled: the page is published at');
  await expectNoViolations(page);
  expect((await request.get(`${SITE}/winter-concert`)).status(), 'not before its time').toBe(404);

  // The scheduler runs every minute; here the test runs it until the time has come.
  await expect.poll(async () => {
    await request.get(`${SITE}/_test/schedule`);

    return (await request.get(`${SITE}/winter-concert`)).status();
  }, { timeout: 150_000, intervals: [10_000] }).toBe(200);
  const run = await (await request.get(`${SITE}/_test/schedule`)).text();
  expect(run, 'a second run does nothing twice').not.toContain('Winter concert');
});

test('an admin turns site search off, and its page is gone', async ({ page, request }) => {
  expect((await request.get(`${SITE}/search?q=block`)).status()).toBe(200);
  await signIn(page, 'ada@example.org');
  await expect(page).toHaveURL(`${SITE}/admin`);
  await page.goto(`${SITE}/admin/features`);
  await page.getByRole('button', { name: 'Turn off Site search' }).click();
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Turn off Site search?');
  await page.getByRole('button', { name: 'Turn off Site search' }).click();
  await expect(page.getByRole('status')).toHaveText('Site search is off. Its data is kept: turn it on again to bring it back.');
  await expectNoViolations(page);

  expect((await request.get(`${SITE}/search?q=block`)).status()).toBe(404);
  await page.goto(`${SITE}/every-block`);
  await expect(page.getByRole('search')).toHaveCount(0);

  await page.goto(`${SITE}/admin/features`);
  await page.getByRole('button', { name: 'Turn on Site search' }).click();
  await expect(page.getByRole('status')).toHaveText('Site search is on.');
  expect((await request.get(`${SITE}/search?q=block`)).status()).toBe(200);
});

test('a member changes their email address with the code sent to the new one', async ({ page, request }) => {
  await signIn(page, 'max@example.org');
  await page.goto(`${SITE}/account`);
  await page.getByRole('link', { name: 'Change your email address' }).click();
  await page.getByLabel('New email address').fill('max@new.example');
  await page.getByLabel('Current password').fill(PASSWORD);
  await page.getByRole('button', { name: 'Send the code' }).click();
  await expect(page).toHaveTitle('Confirm your new email address - Riverside Library');

  await page.getByLabel('6-digit code').fill(codeIn(await mailTo(request, 'max@new.example')));
  await page.getByRole('button', { name: 'Confirm my new email address' }).click();
  await expect(page.getByRole('status')).toHaveText('Your email address is now max@new.example. Sign in with it from now on.');
  await expectNoViolations(page);

  // The old address is told, without a link.
  const notice = await mailTo(request, 'max@example.org');
  for (const body of [notice.text, notice.html ?? '']) {
    expect(body, `“${notice.subject}” has no link`).not.toMatch(/https?:\/\/|<a\s/i);
  }
  await signOut(page);
  await signIn(page, 'max@new.example');
  await expect(page).toHaveURL(`${SITE}/`);
});
