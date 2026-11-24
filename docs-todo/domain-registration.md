# Domain registration (X-7)

## What it does

On a members-only site, people with an email address at the organisation's domains can create their own account,
even while the site's sign-up is off. Same steps as sign-up: a form, a 6-digit code by email, then the site's sign-up
role (Member by default).

## Who uses it

- **Operators** turn it on for the tenant in `config/tenant.php`.
- **Site admins** of members-only sites can turn it off for their site (*Site settings → Sign-up*). They can't turn
  it on or change the domains.
- **Visitors** see "Register with your organisation email" on the sign-in page.

## Settings

```php
'email_policy' => ['domains' => ['example.org', 'example.com']],
'domain_registration' => 'on',                          // the email_policy domains
// or 'domain_registration' => ['domains' => ['example.org']], // some of them
```

- Default `'off'`. `'on'` needs `email_policy` domains; listed domains must be among them. Wrong settings stop
  SightPress from starting with a message.
- Site setting `people.domain_registration`: `tenant` (default) or `off`. `site:create --domain-registration=off`.
- Matching is exact and ignores case: `mail.example.org` only counts when listed; `bücher.example` and
  `xn--bcher-kva.example` are the same.

## Rules

- Only members-only sites; public sites and sites where anyone may sign up are not affected.
- Accounts can't sign in until the emailed code is typed; spam checks and rate limits are those of sign-up.
- The sign-up role is used under the sign-up guardrail (never an unsafe role).
- The audit log records `source: domain` for these registrations.

## Accessibility

The sign-in link is a plain link; refusals go to the focused error summary and the email field (`aria-invalid`).
The site opt-out is a radio group with a legend and a hint. Keyboard test: `tests/A11y/domain-registration.spec.ts`.

## Code and tests

- `src/Auth/Contract/DomainRegistration.php`, `src/Auth/Registration/TenantDomainRegistration.php`,
  `src/Auth/Registration/SelfRegistration.php`, `src/Auth/Http/LoginHandler.php`, `src/Auth/Http/RegisterHandler.php`,
  `src/People/Http/SignUpSettingsHandler.php`, `src/Tenant/TenantPolicies.php`; ADR-0068
- `tests/Unit/Auth/`, `tests/Unit/Tenant/TenantPoliciesTest.php`, `tests/Integration/Auth/DomainRegistrationFlowTest.php`
