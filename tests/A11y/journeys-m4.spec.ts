// The M4 journeys (Q-2), from start to finish on the `extjourney` copy of the demo site (tests/A11y/demo): a tenant
// whose organisation lets people at example.org register on its members-only sites, with text messages on through
// a pretend provider (nothing leaves the server). In order:
// 1. the server installs a module package with Composer, an admin turns it on for the site, uses it, turns it off,
//    and the package is removed;
// 2. an admin creates a custom role and invites someone with it, who accepts with the emailed code;
// 3. sign-up refuses an address outside the organisation's domains and takes one inside them; someone registers on
//    the members-only staff site with their organisation email;
// 4. someone adds a mobile number, chooses text notifications, gets one, replies STOP and gets no more;
// 5. they sign in with the number and reset their password with a code by text;
// 6. an admin turns two-step confirmation on, and changing someone's role then waits for a code;
// 7. someone who lost their phone gets their number removed by the operator, signs in by email and adds a new one.
// Every text and email has a 6-digit code and no link; every screen on the way has no axe violations.
import { createHmac } from 'node:crypto';
import { expect, test, type APIRequestContext, type Page } from '@playwright/test';
import { expectNoViolations } from './support/admin-site';

const SITE = 'http://127.0.0.1:8054';
const STAFF = 'http://localhost:8054';
const PASSWORD = 'Correct horse battery staple 7!';
const SMS_KEY = 'test-sms-key-0123';
const NIA = '+447400123470';
const NIA_NEW = '+447400123471';
const ADA = '+447400123401';

test.describe.configure({ mode: 'serial' });

test.beforeAll(async ({ request }) => {
  test.setTimeout(240_000);
  await request.get(`${SITE}/_test/reset`);
  expect((await request.get(`${SITE}/`, { timeout: 230_000 })).status()).toBe(200);
});

type Mail = { to: string[]; subject: string; text: string; html: string | null };
type Text = { to: string; text: string; count: number };

async function mailTo(request: APIRequestContext, address: string): Promise<Mail> {
  const response = await request.get(`${SITE}/_test/mail?to=${encodeURIComponent(address)}`);
  expect(response.status(), `an email to ${address}`).toBe(200);

  return (await response.json()) as Mail;
}

async function textTo(request: APIRequestContext, number: string): Promise<Text | null> {
  const response = await request.get(`${SITE}/_test/texts?to=${encodeURIComponent(number)}`);
  if (response.status() === 404) {
    return null;
  }
  const text = (await response.json()) as Text;
  expect(text.text, 'no link in a text message').not.toMatch(/https?:|www\./);

  return text;
}

function codeIn(body: string): string {
  expect(body, 'no link').not.toMatch(/https?:\/\/|<a\s/i);
  const code = /\b(\d{3}) ?(\d{3})\b/.exec(body);
  expect(code, 'a 6-digit code').not.toBeNull();

  return `${code?.[1]}${code?.[2]}`;
}

async function signIn(page: Page, identifier: string, password = PASSWORD, site = SITE): Promise<void> {
  await page.context().clearCookies();
  await page.goto(`${site}/login`);
  await page.getByLabel(site === SITE ? 'Email address or mobile number' : 'Email address').fill(identifier);
  await page.getByLabel('Password', { exact: true }).fill(password);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await page.waitForLoadState('load');
}

async function skipPhone(page: Page): Promise<void> {
  if (new URL(page.url()).pathname === '/account/phone') {
    await page.getByRole('button', { name: 'Skip for now' }).click();
    await page.waitForLoadState('load');
  }
}

/** Asks for a code on "Confirm it's you", waits for the new text and types its code. */
async function confirmWithCode(page: Page, request: APIRequestContext, number: string): Promise<string> {
  const before = (await textTo(request, number))?.count ?? 0;
  await page.getByRole('button', { name: 'Send me a code' }).press('Enter');
  await expect(page.getByLabel('6-digit code')).toBeVisible();
  await expect.poll(async () => (await textTo(request, number))?.count ?? 0).toBe(before + 1);
  const text = (await textTo(request, number))?.text ?? '';
  await page.getByLabel('6-digit code').fill(codeIn(text));
  await page.keyboard.press('Enter');
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('You confirmed it’s you');

  return text;
}

/** Adds a mobile number on the phone page that is open, with the code that came by text. */
async function addNumber(page: Page, request: APIRequestContext, national: string, e164: string): Promise<void> {
  await expectNoViolations(page);
  await page.getByLabel('Mobile number').fill(national);
  await page.getByLabel('Mobile number').press('Enter');
  await expect(page.getByLabel('6-digit code')).toBeVisible();
  await expectNoViolations(page);
  const text = await textTo(request, e164);
  await page.getByLabel('6-digit code').fill(codeIn(text?.text ?? ''));
  await page.keyboard.press('Enter');
  // After the sign-in prompt they go on to where they were going; My account shows the number either way.
  await expect(page.getByLabel('6-digit code')).toHaveCount(0);
  await page.goto(`${SITE}/account`);
  await expect(page.getByText(`Mobile number: ${national}`)).toBeVisible();
}

/** A contributor's page goes to review, and the scheduler sends what is in the text outbox. */
async function submitForReview(page: Page, request: APIRequestContext, title: string): Promise<void> {
  await signIn(page, 'cleo@example.org');
  await skipPhone(page);
  await page.goto(`${SITE}/admin/pages/new`);
  await page.getByLabel('Title', { exact: true }).fill(title);
  await page.getByRole('button', { name: 'Start the page' }).click();
  await page.getByLabel('Description for search engines').fill(`${title}, for the journey.`);
  await page.getByRole('button', { name: 'Save page' }).first().click();
  await page.getByRole('button', { name: 'Submit for review' }).click();
  await expect(page.getByRole('status')).toBeVisible();
  expect((await request.get(`${SITE}/_test/schedule`)).status()).toBe(200);
}

test('a module is installed with Composer, turned on for the site, used, turned off and removed', async ({ page, request }) => {
  test.setTimeout(240_000);
  await signIn(page, 'ada@example.org');
  await skipPhone(page);
  await page.goto(`${SITE}/admin/features`);
  await expect(page.locator('main')).not.toContainText('acme.events');

  // composer require acme/events
  expect((await request.get(`${SITE}/_test/journey/install-module`)).status()).toBe(204);
  await page.goto(`${SITE}/admin/features`);
  await expect(page.locator('main')).toContainText('acme.events');
  await expectNoViolations(page);
  const turnOn = page.getByRole('button', { name: 'Turn on acme.events' });
  if (await turnOn.count() > 0) {
    await turnOn.press('Enter');
    await expect(page.getByRole('status')).toBeVisible();
  }
  await expect(page.getByRole('button', { name: 'Turn off acme.events' })).toBeVisible();

  // The admin uses it: the Events screen is in the admin menu.
  await page.getByRole('navigation', { name: 'Admin' }).getByRole('link', { name: 'Events' }).click();
  await expect(page).toHaveURL(`${SITE}/admin/events`);
  await expectNoViolations(page);
  await page.getByLabel('Name').fill('Book fair');
  await page.getByLabel('Starts at').fill('2026-12-05T10:00');
  await page.getByRole('button', { name: 'Add event' }).press('Enter');
  await expect(page.getByRole('status')).toHaveText('Added “Book fair”.');
  await expectNoViolations(page);
  const event = await page.goto(`${SITE}/events/book-fair`);
  expect(event?.status()).toBe(200);
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Book fair');
  await expectNoViolations(page);
  expect(await (await request.get(`${SITE}/sitemap.xml`)).text()).toContain(`${SITE}/events/book-fair`);

  // Turned off: its pages are gone, its data is kept.
  await page.goto(`${SITE}/admin/features`);
  await page.getByRole('button', { name: 'Turn off acme.events' }).click();
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Turn off acme.events?');
  await expectNoViolations(page);
  await page.getByRole('button', { name: 'Turn off acme.events' }).click();
  await expect(page.getByRole('button', { name: 'Turn on acme.events' })).toBeVisible();
  expect((await request.get(`${SITE}/events/book-fair`)).status()).toBe(404);
  expect((await page.goto(`${SITE}/admin/events`))?.status()).toBe(404);
  expect(await (await request.get(`${SITE}/sitemap.xml`)).text()).not.toContain('/events/');

  // composer remove acme/events: the site doesn't notice, and it is no longer offered.
  expect((await request.get(`${SITE}/_test/journey/remove-module`)).status()).toBe(204);
  expect((await page.goto(`${SITE}/admin/features`))?.status()).toBe(200);
  await expect(page.locator('main')).not.toContainText('acme.events');
  expect((await request.get(`${SITE}/`)).status()).toBe(200);
  await expectNoViolations(page);
});

test('an admin creates a custom role and invites someone with it', async ({ page, request }) => {
  test.setTimeout(120_000);
  await signIn(page, 'ada@example.org');
  await skipPhone(page);
  await page.goto(`${SITE}/admin/roles`);
  await page.getByRole('link', { name: 'Create a role' }).click();
  await page.getByLabel('Name of the role').fill('Shop staff');
  await page.getByRole('group', { name: 'Media (optional)' }).getByRole('checkbox', { name: 'Upload media' }).check();
  await page.getByRole('button', { name: 'Create role' }).press('Enter');
  await expect(page.getByRole('status')).toHaveText('Created the role “Shop staff”. Give it to people on their page in People.');

  await page.goto(`${SITE}/admin/users/invite`);
  await page.getByLabel('Email address').fill('sol@example.org');
  await page.getByRole('group', { name: 'Role' }).getByRole('radio', { name: /^Shop staff/ }).check();
  await page.getByLabel('First name (optional)').fill('Sol');
  await page.getByRole('button', { name: 'Send invitation' }).click();
  await expect(page.getByRole('status')).toContainText('We have sent an invitation to sol@example.org');
  await expectNoViolations(page);

  const code = codeIn((await mailTo(request, 'sol@example.org')).text);
  await page.context().clearCookies();
  await page.goto(`${SITE}/login`);
  await page.getByRole('link', { name: /invitation/i }).click();
  await page.getByLabel('Email address').fill('sol@example.org');
  await page.getByLabel('6-digit code').fill(code);
  await page.getByRole('button', { name: 'Continue' }).click();
  await page.getByLabel('Last name').fill('Reyes');
  await page.getByLabel('Password').fill('A long password for Sol 5!');
  await page.getByRole('button', { name: 'Create my account' }).click();
  await expect(page.getByRole('status')).toHaveText('Your account is ready. Sign in with sol@example.org and your new password.');
  await signIn(page, 'sol@example.org', 'A long password for Sol 5!');
  await skipPhone(page);
  await expectNoViolations(page);
  // Shop staff upload media, and nothing else in the admin.
  expect((await page.goto(`${SITE}/admin/media`))?.status()).toBe(200);
  expect((await page.goto(`${SITE}/admin/pages`))?.status()).toBe(403);
  expect((await page.goto(`${SITE}/admin/roles`))?.status()).toBe(403);
});

test('sign-up takes only the organisation’s addresses, and its people register on the members-only site', async ({ page, request }) => {
  test.setTimeout(120_000);
  await page.context().clearCookies();
  await page.goto(`${SITE}/register`);
  await page.getByLabel('First name').fill('Uma');
  await page.getByLabel('Last name').fill('Outside');
  await page.getByLabel('Email address').fill('uma@elsewhere.example');
  await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
  await page.getByRole('button', { name: 'Create my account' }).click();
  await expect(page.locator('main')).toContainText('example.org');
  await expect(page.getByLabel('Email address')).toHaveAttribute('aria-invalid', 'true');
  await expectNoViolations(page);
  expect((await request.get(`${SITE}/_test/mail?to=uma%40elsewhere.example`)).status(), 'nothing sent').toBe(404);

  await page.getByLabel('Email address').fill('nia@example.org');
  await page.getByLabel('First name').fill('Nia');
  await page.getByLabel('Last name').fill('Lind');
  await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
  await page.getByRole('button', { name: 'Create my account' }).click();
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Confirm your email address');
  await page.getByLabel('6-digit code').fill(codeIn((await mailTo(request, 'nia@example.org')).text));
  await page.getByRole('button', { name: 'Confirm my email address' }).click();
  await expect(page.getByRole('status')).toContainText('Your email address is confirmed');

  // The members-only staff site: register with the organisation email.
  await page.goto(`${STAFF}/login`);
  await page.getByRole('link', { name: 'Register with your organisation email' }).click();
  await expectNoViolations(page);
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Create an account on Riverside Staff');
  await page.getByLabel('First name').fill('Oli');
  await page.getByLabel('Last name').fill('Berg');
  await page.getByLabel('Email address').fill('oli@elsewhere.example');
  await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
  await page.getByRole('button', { name: 'Create my account' }).click();
  await expect(page.getByLabel('Email address')).toHaveAttribute('aria-invalid', 'true');
  await expect(page.locator('main')).toContainText('example.org');
  await expectNoViolations(page);
  await page.getByLabel('Email address').fill('oli@example.org');
  await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
  await page.getByRole('button', { name: 'Create my account' }).click();
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Confirm your email address');
  await expectNoViolations(page);
  await page.getByLabel('6-digit code').fill(codeIn((await mailTo(request, 'oli@example.org')).text));
  await page.getByRole('button', { name: 'Confirm my email address' }).click();
  await expect(page.getByRole('status')).toContainText('Your email address is confirmed');
  // Oli is a member of the staff site and reads it.
  await signIn(page, 'oli@example.org', PASSWORD, STAFF);
  expect((await page.goto(`${STAFF}/`))?.status()).toBe(200);
});

test('someone adds a number, gets a notification by text, replies STOP and gets no more', async ({ page, request }) => {
  test.setTimeout(240_000);
  // Ada makes Nia an editor, so she reviews pages.
  await signIn(page, 'ada@example.org');
  await skipPhone(page);
  await page.goto(`${SITE}/admin/users`);
  await page.getByRole('link', { name: 'Nia' }).click();
  await page.getByRole('group', { name: 'Role' }).getByRole('radio', { name: /^Editor/ }).check();
  await page.getByRole('button', { name: 'Change role' }).click();
  await expect(page.getByRole('status')).toHaveText('Nia’s role is now Editor.');

  // Nia signs in, is asked for a number, adds it and chooses the review texts.
  await signIn(page, 'nia@example.org');
  await expect(page).toHaveURL(new RegExp(`${SITE}/account/phone`));
  await addNumber(page, request, '07400 123470', NIA);
  await page.goto(`${SITE}/account/phone`);
  await page.getByLabel('Send me text messages').check();
  await page.getByLabel('When a page is sent to you for review').check();
  await page.getByRole('button', { name: 'Save my choices' }).press('Enter');
  await expect(page.getByLabel('Send me text messages')).toBeChecked();
  await expectNoViolations(page);
  const before = (await textTo(request, NIA))?.count ?? 0;

  await submitForReview(page, request, 'Story time');
  const notified = await textTo(request, NIA);
  expect(notified?.count).toBe(before + 1);
  expect(notified?.text).toBe('Riverside Library: a page is waiting for your review. Sign in to the admin panel to see it.');

  // Nia replies STOP; the provider calls the site's webhook, signed.
  const body = JSON.stringify({ from: NIA, text: 'STOP' });
  const unsigned = await request.post(`${SITE}/_sms/webhook`, { data: body, headers: { 'Content-Type': 'application/json' } });
  expect(unsigned.status(), 'an unsigned reply changes nothing').toBe(403);
  const stop = await request.post(`${SITE}/_sms/webhook`, {
    data: body,
    headers: { 'Content-Type': 'application/json', 'X-Test-Sms-Signature': createHmac('sha256', SMS_KEY).update(body).digest('hex') },
  });
  expect(stop.status()).toBe(200);

  await submitForReview(page, request, 'Craft club');
  expect((await textTo(request, NIA))?.count, 'no text after STOP').toBe(before + 1);
  expect((await mailTo(request, 'nia@example.org')).subject, 'the email still comes').toBe('Please review "Craft club" on Riverside Library');
});

test('someone signs in with their number and resets their password by text', async ({ page, request }) => {
  test.setTimeout(120_000);
  // Max, a member, adds a number first.
  await signIn(page, 'max@example.org');
  await addNumber(page, request, '07400 123404', '+447400123404');

  await signIn(page, '07400 123404');
  await expect(page).toHaveURL(`${SITE}/`);
  await page.context().clearCookies();

  await page.goto(`${SITE}/login/forgot`);
  await page.getByLabel('Email address or mobile number').fill('+44 7400 123404');
  await page.keyboard.press('Enter');
  await expect(page).toHaveTitle('Enter your code - Riverside Library');
  await expectNoViolations(page);
  await page.getByLabel('6-digit code').fill(codeIn((await textTo(request, '+447400123404'))?.text ?? ''));
  await page.keyboard.press('Enter');
  await page.getByLabel('New password').fill('A brand new long password 9!');
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(`${SITE}/login`);
  expect((await mailTo(request, 'max@example.org')).subject).toBe('The password of your account on Riverside Library was changed');
  await signIn(page, '07400 123404', 'A brand new long password 9!');
  await expect(page).toHaveURL(`${SITE}/`);
});

test('with two-step on, changing someone’s role waits for a code', async ({ page, request }) => {
  test.setTimeout(180_000);
  await signIn(page, 'ada@example.org');
  await page.goto(`${SITE}/account/phone`);
  await addNumber(page, request, '07400 123401', ADA);

  await page.goto(`${SITE}/admin/settings/security`);
  await page.getByRole('radio', { name: 'On: important changes need a code sent by text message' }).check();
  await page.getByRole('button', { name: 'Save' }).press('Enter');
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Confirm it’s you');
  await confirmWithCode(page, request, ADA);
  await page.getByRole('button', { name: 'Make the change' }).press('Enter');
  await expect(page.getByText('It is on.')).toBeVisible();

  // A new session: the confirmation doesn't carry over.
  await signIn(page, 'ada@example.org');
  await page.goto(`${SITE}/admin/users`);
  await page.getByRole('link', { name: 'Cleo' }).click();
  const cleo = page.url();
  await page.getByRole('group', { name: 'Role' }).getByRole('radio', { name: /^Shop staff/ }).check();
  await page.getByRole('button', { name: 'Change role' }).click();
  await expect(page).toHaveURL(`${SITE}/confirm`);
  await expect(page.getByText('Nothing has changed yet.')).toBeVisible();
  await expectNoViolations(page);
  await page.goto(cleo);
  await expect(page.getByRole('group', { name: 'Role' }).getByRole('radio', { name: /^Contributor/ })).toBeChecked();

  await page.getByRole('group', { name: 'Role' }).getByRole('radio', { name: /^Shop staff/ }).check();
  await page.getByRole('button', { name: 'Change role' }).click();
  expect(await confirmWithCode(page, request, ADA)).toMatch(/^\d{3} \d{3} is your code to confirm a change/);
  await expectNoViolations(page);
  await page.getByRole('button', { name: 'Make the change' }).press('Enter');
  await expect(page.getByRole('status')).toHaveText('Cleo’s role is now Shop staff.');
});

test('someone who lost their phone gets the number removed and adds a new one', async ({ page, request }) => {
  test.setTimeout(120_000);
  // The operator removes Nia's number on the server; the number is never printed.
  const reset = (await (await request.get(`${SITE}/_test/journey/phone-reset?email=nia%40example.org`)).json()) as { status: number; output: string };
  expect(reset.status, reset.output).toBe(0);
  expect(reset.output).not.toContain('123470');

  // The old number no longer signs her in; her email address does, and she adds her new number.
  await signIn(page, '07400 123470');
  await expect(page).toHaveURL(new RegExp(`${SITE}/login`));
  await signIn(page, 'nia@example.org');
  await expect(page).toHaveURL(new RegExp(`${SITE}/account/phone`));
  await addNumber(page, request, '07400 123471', NIA_NEW);
  await signIn(page, '07400 123471');
  await expect(page).not.toHaveURL(new RegExp(`${SITE}/login`));
});
