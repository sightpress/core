# Privacy model (GD-0)

## What it does

Sets out how SightPress handles personal data (ADR-0075): the site owner is the controller, every data set a site
holds is listed with its purpose, suggested lawful basis, retention and erasure rule, erasure replaces the person
with a *Former user* tombstone, and a fresh site sets no cookies for anonymous visitors and calls no third party
apart from its own mailer. The module contract for it is ADR-0076 … ADR-0080 (`module-interface` 0.6).

Plain-language explanation of the law, never legal advice: lawful bases and retention periods are suggestions, and
the site owner decides.

## Who uses it

- Site owners and admins: what the generated Privacy Policy and record of processing will say (GD-5).
- Operators: the roles (an operator hosting sites for others is a processor) and the processing-agreement template.
- Module developers: declaring personal data, cookies and processors (ADR-0077).

## Settings, commands, routes

None yet. GD-1 … GD-8 add *Settings → Privacy*, *My account → Your data*, `privacy:purge`,
`site:user:export|erase`, `site:privacy:report` and `site:privacy:reapply-erasures`.

## Links

- `docs/adr/0075-privacy-model.md` (the inventory table)
- `docs/adr/0077-privacy-contract.md`
