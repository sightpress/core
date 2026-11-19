# Architecture Decision Records

This folder holds the Architecture Decision Records (ADRs) for SightPress. They cover `core` and the
shared decisions for `module-interface`, `skeleton` and `docs`.

An ADR records one decision: the context, the decision and its consequences. It also has a status:
*Proposed*, *Accepted*, *Superseded by ADR-NNNN* or *Rejected*.

## Rules

- File names: `NNNN-short-title.md` (four digits, lowercase, hyphens), e.g. `0001-license.md`.
- Start from `0000-template.md`.
- A new ADR starts as *Proposed*. A maintainer sets it to *Accepted*.
- Never edit an accepted ADR's decision. Write a new ADR that supersedes it.
- Any change to a public contract (`src/*/Contract/` or the `sightpress/module-interface` package) needs an ADR first.

## Index

| ADR | Title | Status |
| --- | --- | --- |
| [0001](0001-license.md) | License: GPL-3.0-or-later | Accepted |
| [0002](0002-tenant-registry-config-file.md) | Tenant registry as a config file | Accepted |
| [0003](0003-site-scoped-di-container.md) | Site-scoped DI container | Accepted |
| [0004](0004-server-rendered-admin.md) | Server-rendered admin panel (no SPA) | Accepted |
| [0005](0005-block-storage-json-typed-schemas.md) | Block storage as JSON with typed schemas | Accepted |
| [0006](0006-workflow-states.md) | Page workflow states | Accepted |
| [0007](0007-url-and-locale-strategy.md) | URL and locale strategy | Accepted |
| [0008](0008-accessibility-testing-strategy.md) | Accessibility testing strategy | Accepted |
| [0009](0009-module-architecture.md) | Module architecture | Accepted |
| [0010](0010-ai-assistant.md) | AI assistant | Accepted |
| [0011](0011-site-search.md) | Site search | Accepted |
| [0012](0012-analytics-privacy-model.md) | Analytics privacy model | Accepted |
| [0013](0013-framework-and-library-baseline.md) | Framework and library baseline | Accepted |
| [0014](0014-permission-allowed-on-sign-up.md) | Permissions say whether sign-up roles may hold them | Accepted |
| [0015](0015-m1-dependency-baseline.md) | Runtime dependencies for M1 (amends ADR-0013) | Accepted |
| [0016](0016-module-entities-and-site-services.md) | Module services live in the site container; core modules map entities from a core list | Accepted |
| [0017](0017-verification-codes-not-links.md) | Verification messages carry a six-digit code, never a link | Accepted |
| [0018](0018-page-editor-from-block-schemas.md) | The page editor builds its fields from block schemas | Accepted |
| [0019](0019-site-settings-in-the-site.md) | Site settings live in the site, and some of them win over the registry | Accepted |
| [0020](0020-demo-content-in-site-provisioning.md) | Demo content is a flag on site provisioning, and steps can share ids | Accepted |
| [0021](0021-guided-command-questions.md) | Commands ask guided questions, described by their parameters | Accepted |
| [0022](0022-site-password-policy.md) | Every site has a password policy, and the Passwords contract describes it | Accepted |
| [0023](0023-search-dashboard-and-assistant-contracts.md) | Search, dashboard and AI assistant contracts (`module-interface` 0.4) | Proposed |
| [0024](0024-site-languages-and-translation-drafts.md) | Site languages live in the site, translations can be drafts of their own | Proposed |
| [0025](0025-locale-fallback-and-sitemaps-per-language.md) | Locale fallback redirects, home page negotiation and a sitemap per language | Proposed |
| [0026](0026-interface-text-translations.md) | Interface text is translated from XLIFF catalogues, with messages instead of built sentences | Proposed |
| [0027](0027-page-operations-and-redirects.md) | Page operations: archive and trash steps, a redirects table and "gone" addresses | Proposed |
| [0028](0028-page-revisions-compared-and-restored.md) | Page revisions: versions per language, a word-by-word comparison and restoring without publishing | Proposed |
| [0029](0029-scheduled-publishing-and-schedule-run.md) | Scheduled publishing and `schedule:run`, the one command for jobs at a set time | Proposed |
| [0030](0030-self-registration-with-an-emailed-code.md) | Self-registration with an emailed code, an `unverified` account status and a sign-up role contract | Proposed |
| [0031](0031-members-only-visibility.md) | Members-only visibility: a site setting, a gate before the routes and "open to everyone" computed once | Proposed |
| [0032](0032-menu-entries-per-language-and-for-members.md) | Menu entries per language and for members, links that follow moved pages | Proposed |
| [0033](0033-profile-password-email-and-admin-preferences.md) | Profile: password and email address changed from My account, and admin preferences as classes | Proposed |
| [0034](0034-per-site-module-switches.md) | Per-site module switches: one registry for the tenant, a filtered view per site | Proposed |
| [0035](0035-site-search-index-in-the-site-database.md) | Site search: an index in the site database, kept up to date by events | Proposed |
| [0036](0036-search-results-page-as-a-public-screen.md) | The search results page: a public screen with the site's menus, routes before the page catch-all | Proposed |
| [0037](0037-analytics-recorded-after-the-response.md) | Analytics: recorded after the response, rolled up daily in the site database | Proposed |

When an ADR's status changes, update it here too.
