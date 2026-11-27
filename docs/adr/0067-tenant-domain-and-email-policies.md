# 0067 — Tenant domain and email policies

- Status: Accepted
- Date: 2026-11-24

## Context

An organisation running SightPress for its own teams wants every site on its own domain and every account on its own
email domains (plan §1.2 *Domain policy*, *Email policy*). The `EmailPolicy` check points existed since M1 with only
`AnyEmailPolicy` behind them; nothing checked domains. Stage-4 gap 4 asks what happens to sites and accounts that
are already outside a policy when it is tightened.

## Decision

- Two optional tenant settings in `config/tenant.php`, read into `TenantPolicies` and checked when the kernel starts
  (a wrong shape or a value that isn't a domain stops it with a message):
  - `domain_policy`: `'any'` (default) or `['subdomain_of' => 'example.org']` — a site's domain must be
    `example.org` itself or a name under it;
  - `email_policy`: `'any'` (default) or `['domains' => ['example.org', …]]` — the part after the last `@` must be
    one of them exactly; subdomains match only when listed.
- Names are compared after lower-casing, dropping a trailing dot and turning international names into their ASCII
  (punycode) form, with label boundaries respected: `evil-example.org` and `example.org.evil.test` are not under
  `example.org`. `site:create` also stores international domains in their ASCII form, as browsers send them.
- `email_policy` is checked by `DomainsEmailPolicy` (chosen by the auth module from the tenant setting) wherever an
  address joins or changes: invitation, accepting it, sign-up, *My account* email change, and the first admin of
  `site:create`. `domain_policy` is checked by `site:create`; no other command changes a site's domains yet, and any
  that does must call `TenantPolicies::domainProblem()`. Each refusal names what is allowed.
- **Tightening removes nothing** (gap 4): sites keep answering on their domains and accounts keep signing in.
  `system:check` lists each site with a domain outside the policy and how many of a site's accounts are outside it,
  as notes, so monitoring doesn't turn red for a deliberate change.

## Consequences

- Operators can lock a tenant to the organisation's domains without code; domain registration (X-7) builds on the
  same email domains.
- `EmailPolicy::problem()` returns a translatable `Message` instead of a string.
- Counting accounts outside the policy opens every site's database once per `system:check` run when
  `email_policy` is set.
