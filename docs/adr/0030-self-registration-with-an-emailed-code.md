# 0030 — Self-registration with an emailed code, an `unverified` account status and a sign-up role contract

- Status: Proposed
- Date: 2026-11-18

## Context

U-1 lets visitors make their own account while a site setting allows it (off by default). The account must not be
usable until the person proves they read the address, with a six-digit code and never a link (D30). Nobody may learn
which addresses have an account. People who sign up get the site's sign-up role, which must always be sign-up-safe
(ADR-0014), even if someone changed the stored value straight in the database. Accounts never confirmed must not pile
up. Open points: where the half-made account lives, which module owns the pages (the module-boundary rule forbids
using another module's internals), and how the admin screen reaches the sign-up role.

## Decision

- **A new account status `unverified`** (`UserStatus::Unverified`). The account is made when the form is sent, with
  its names, time zone, language and password hash, but it can't sign in (`isActive()` is false) and gets no avatar
  and no role until the code is typed. `Account::awaitsEmailVerification()` is added to the `User\Contract\Account`
  interface, and a new contract `User\Contract\UnverifiedAccounts` (`startUnverified()`, `confirm()`,
  `forgetUnconfirmed()`) is implemented by `AccountRegistrar`. An unverified account can't be deactivated.
- **Signing up again** with an address whose account still waits for its code replaces that account's details and
  password: whoever proves the address keeps the account, so someone who signed up with another person's address
  can't take it over later.
- **No enumeration**: an address with any other account gets exactly the same answer (the code page) and the same
  limits (`VerificationCodes::request()` is called either way). Its owner gets a notice email that someone tried to
  sign up, also without a link, and nothing changes.
- **Sign-in**: only with the right password does `SignIn` report that an account waits for its code
  (`SignInResult::unconfirmed()`); the sign-in page then sends the person to the code page, where "Send a new code"
  works.
- **The auth module owns the pages** (`/register`, `/register/code`, `Auth\Registration\SelfRegistration`), next to
  sign-in and the other code pages, and the `Auth\Contract\SignUp` constants (setting `people.registration`, paths).
  The email policy moves with them: `People\EmailPolicy` becomes `Auth\Contract\EmailPolicy` (a public service of
  the auth module, still `AnyEmailPolicy` until M4) and is checked on invitations and on sign-up.
- **The sign-up role** is reached through a new contract `Rbac\Contract\SignUpRoles` (implemented by
  `RegistrationRole`): `choices()` lists only sign-up-safe roles, `change()` refuses others, and `forNewAccount()`
  gives `member` when the stored role is unsafe or gone. The new account gets the role on confirmation and the
  `Auth\Contract\AccountRegistered` event is audited (`user.registered`, with `refused_role` when it fell back).
- **The admin screen** `/admin/settings/sign-up` (people module, `settings.manage`) turns sign-up on and off and picks
  the role; changes dispatch `SettingsChanged`.
- **Spam**: a hidden field only bots fill in (they get the same answer; nothing is made or sent) and a new
  `registration` rate limit (10 forms an hour per client address), on top of the code limits. No image puzzles and no
  third-party challenge; an optional self-hosted challenge (gap 5) is left for later.
- **Clean-up**: the auth module listens to `ScheduleDue` and removes accounts still unverified a week after they were
  made.

## Consequences

- Anything that lists or counts users sees unverified accounts (the people screen labels them "Hasn’t confirmed their
  email address yet"); code that needs people who can sign in must check `isActive()`.
- Moving `EmailPolicy` changes its namespace; nothing outside core used it.
- Domain registration on members-only sites (M4, X-7) can reuse the same flow and the `EmailPolicy` check point.
