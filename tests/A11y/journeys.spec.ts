// Journeys from start to finish (C-8.2) on a copy of the demo site (tests/A11y/demo, made with
// site:create --with-demo-content, in production mode) whose test mailbox keeps every email the site sends:
// - a contributor writes a page and sends it for review, the editors are told by email, an editor approves and
//   publishes it, and only then can visitors read it, find it in the sitemap and llms.txt, and get its Markdown;
// - an admin invites someone, who types the six-digit code from their email, sets up their account and signs in;
// - someone who forgot their password asks for a code, types the one from their email and signs in with a new
//   password, while the old one stops working.
// No email with a code has a link in it. The screens on the way have no axe violations.
import { expect, test, type APIRequestContext, type Page } from '@playwright/test';
import { expectNoViolations } from './support/admin-site';

const SITE = 'http://127.0.0.1:8086';
const PASSWORD = 'correct horse battery staple';

test.describe.configure({ mode: 'serial' });

test.beforeAll(async ({ request }) => {
  test.setTimeout(60_000);
  await request.get(`${SITE}/_test/reset`);
  expect((await request.get(`${SITE}/`)).status()).toBe(200);
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
  expect(await (await request.get(`${SITE}/sitemap.xml`)).text()).toContain(`<loc>${SITE}/book-club</loc>`);
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
  await page.getByLabel('Password').fill('a long password for zoe');
  await page.getByRole('button', { name: 'Create my account' }).click();
  await expect(page.getByRole('status')).toHaveText('Your account is ready. Sign in with zoe@example.org and your new password.');

  await signIn(page, 'zoe@example.org', 'a long password for zoe');
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
  await page.getByLabel('New password').fill('a completely new password');
  await page.getByLabel('New password').press('Enter');
  await expect(page.getByRole('status')).toHaveText('Your password has been changed. Sign in with your new password.');

  await signIn(page, 'bea@example.org');
  await expect(page).toHaveURL(/\/login/);
  await signIn(page, 'bea@example.org', 'a completely new password');
  await expect(page).toHaveURL(`${SITE}/admin`);
});
