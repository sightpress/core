# Tenant policies: domains and email addresses (X-5)

## What it does

Lets the organisation that runs SightPress keep every site on its own domain and every account on its own email
domains. Set in `config/tenant.php`; nothing changes until a policy is set.

## Who uses it

**Operators** (whoever edits `config/tenant.php`). Site admins and visitors only see the refusals.

## Settings

```php
'domain_policy' => ['subdomain_of' => 'example.org'],          // default 'any'
'email_policy' => ['domains' => ['example.org', 'example.com']], // default 'any'
```

- `domain_policy`: a site's domain must be `example.org` or a name under it (`www.example.org`, `news.example.org`).
  `evil-example.org` and `example.org.evil.test` don't count. Checked by `site:create`.
- `email_policy`: only addresses at exactly those domains may be invited, accept an invitation, sign up, become
  someone's new address on *My account*, or be the first admin of `site:create`. Subdomains count only when listed.
- Case, a trailing dot and international names (`bücher.example` = `xn--bcher-kva.example`) don't matter.
- A setting written wrongly stops SightPress from starting, with a message saying what is wrong.

## Tightening a policy

Nothing is removed: existing sites keep working and existing accounts keep signing in. `system:check` prints a note
for each site with a domain outside `domain_policy` and for each site with accounts outside `email_policy` (how many).

## Code and tests

- `src/Tenant/TenantPolicies.php`, `src/Tenant/DomainName.php`, `src/Tenant/PolicyReport.php`,
  `src/Auth/DomainsEmailPolicy.php`, `src/Site/Command/CreateSiteCommand.php`; ADR-0067
- `tests/Unit/Tenant/`, `tests/Unit/Site/Command/SiteCommandsTest.php`,
  `tests/Integration/Tenant/EmailPolicyFlowTest.php`
