# 0068 — Domain registration on members-only sites

- Status: Proposed
- Date: 2026-11-24

## Context

Plan §1.2 *Domain registration* and D9: on a members-only site, people at the organisation's email domains should
be able to join without an invitation, even while the site's sign-up is off, and site admins must be able to opt
out. Self-registration (U-1) already has the form, the emailed code, the spam checks and the sign-up guardrail.

## Decision

- Tenant setting `domain_registration`: `'off'` (default), `'on'` (the `email_policy` domains) or
  `['domains' => […]]`. Checked when the kernel starts: `'on'` needs `email_policy` domains, and listed domains must
  be among them; names are normalised like every policy (ADR-0067) and matched exactly (subdomains only if listed).
- It applies only to sites with `site.visibility: members`, and a site follows the tenant unless its admins turn it
  off: site setting `people.domain_registration` = `tenant` (default) or `off`, on the *Sign-up* screen (shown only
  when the tenant offers it to that site) or `site:create --domain-registration=off`. There is no way to turn it on
  from a site, or to change the domains.
- It reuses self-registration rather than adding a second flow: `/register` and `/register/code` open when sign-up
  is open to anyone *or* domain registration is on. While only domain registration lets people in, the sign-in page
  links "Register with your organisation email", the form says which domains, and other addresses are refused
  naming them. Everything else is U-1: inactive until the 6-digit code is typed, honeypot and rate limit, the site's
  sign-up role under the guardrail (`member` by default, never an unsafe role). With sign-up open to anyone, or on a
  public site, nothing changes.
- The audit entry of a registration has `source`: `domain` when the site's sign-up was off (so only domain
  registration could have let the person in), otherwise `self`. `AccountRegistered` (module-interface) is unchanged;
  the recorder reads the site's sign-up setting.

## Consequences

- Organisations get "anyone with a work address can join" without inviting each person, and site admins keep the
  last word for their site.
- `SelfRegistration` depends on the new `Auth\Contract\DomainRegistration`; `site:create` gains an option.
- A registration started while sign-up was open and confirmed after it closed is recorded as `domain`; rare, and
  the role and checks are the same either way.
