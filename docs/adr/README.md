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
| [0023](0023-search-dashboard-and-assistant-contracts.md) | Search, dashboard and AI assistant contracts (`module-interface` 0.4) | Accepted |
| [0024](0024-site-languages-and-translation-drafts.md) | Site languages live in the site, translations can be drafts of their own | Accepted |
| [0025](0025-locale-fallback-and-sitemaps-per-language.md) | Locale fallback redirects, home page negotiation and a sitemap per language | Accepted |
| [0026](0026-interface-text-translations.md) | Interface text is translated from XLIFF catalogues, with messages instead of built sentences | Accepted |
| [0027](0027-page-operations-and-redirects.md) | Page operations: archive and trash steps, a redirects table and "gone" addresses | Accepted |
| [0028](0028-page-revisions-compared-and-restored.md) | Page revisions: versions per language, a word-by-word comparison and restoring without publishing | Accepted |
| [0029](0029-scheduled-publishing-and-schedule-run.md) | Scheduled publishing and `schedule:run`, the one command for jobs at a set time | Accepted |
| [0030](0030-self-registration-with-an-emailed-code.md) | Self-registration with an emailed code, an `unverified` account status and a sign-up role contract | Accepted |
| [0031](0031-members-only-visibility.md) | Members-only visibility: a site setting, a gate before the routes and "open to everyone" computed once | Accepted |
| [0032](0032-menu-entries-per-language-and-for-members.md) | Menu entries per language and for members, links that follow moved pages | Accepted |
| [0033](0033-profile-password-email-and-admin-preferences.md) | Profile: password and email address changed from My account, and admin preferences as classes | Accepted |
| [0034](0034-per-site-module-switches.md) | Per-site module switches: one registry for the tenant, a filtered view per site | Accepted |
| [0035](0035-site-search-index-in-the-site-database.md) | Site search: an index in the site database, kept up to date by events | Accepted |
| [0036](0036-search-results-page-as-a-public-screen.md) | The search results page: a public screen with the site's menus, routes before the page catch-all | Accepted |
| [0037](0037-analytics-recorded-after-the-response.md) | Analytics: recorded after the response, rolled up daily in the site database | Accepted |
| [0038](0038-accessible-charts-drawn-on-the-server.md) | Accessible charts drawn on the server as SVG, with summaries and tables | Accepted |
| [0039](0039-dashboard-widgets-and-the-analytics-screen.md) | Dashboard widgets drawn by the admin, and the Analytics screen | Accepted |
| [0040](0040-assistant-providers-keys-and-budget.md) | AI assistant: providers over the shared HTTP client, keys, budget and limits | Accepted |
| [0041](0041-assistant-panel-suggest-buttons-and-insert.md) | AI assistant: the settings screen, the panel, Suggest buttons and Insert without JavaScript | Accepted |
| [0042](0042-assistant-drafting-tasks-and-unchecked-alt-text.md) | AI assistant: drafting tasks, checked output, and alt text that needs a person | Accepted |
| [0043](0043-assistant-help-from-the-documentation.md) | AI assistant: help answered from the documentation | Accepted |
| [0044](0044-assistant-drafted-translations.md) | AI assistant: translations drafted by the assistant, checked by a person | Accepted |
| [0045](0045-theme-architecture-and-preview.md) | Themes: the architecture in theme-interface, and the static preview page | Accepted |
| [0046](0046-cloud-storage-and-large-uploads.md) | Cloud storage per site and how large files get there | Accepted |
| [0047](0047-theme-discovery-and-per-site-themes.md) | Themes: discovery, a theme per site, and an admin that keeps the default theme | Accepted |
| [0048](0048-theme-picker-and-site-colours.md) | Themes: the admin theme picker, published previews and a site's own colours | Accepted |
| [0049](0049-standard-layouts.md) | Themes: the standard layouts, the side column, and switching to a theme without a layout | Accepted |
| [0050](0050-per-site-upload-limits.md) | Per-site upload limits, the upload probe and refused-upload counts | Accepted |
| [0051](0051-cdn-delivery-and-signed-media-urls.md) | CDN delivery and signed media addresses | Accepted |
| [0052](0052-command-line-media-import.md) | Command-line media import | Accepted |
| [0053](0053-cloud-storage-provisioning.md) | Cloud storage provisioning | Accepted |
| [0054](0054-chunked-uploads-to-a-temporary-folder.md) | Chunked uploads to a temporary folder | Accepted |
| [0055](0055-direct-to-bucket-uploads.md) | Direct-to-bucket uploads | Accepted |
| [0056](0056-captions-and-transcripts.md) | Captions and transcripts | Accepted |
| [0057](0057-module-contract-audit.md) | Module contract audit: what an external module can reach (`module-interface` 0.5 scope) | Accepted |
| [0058](0058-public-events-in-the-module-contract.md) | Public events in the module contract | Accepted |
| [0059](0059-module-screens-viewer-site-settings-audit.md) | Module screens, the viewer, site information, settings, audit and translations | Accepted |
| [0060](0060-module-seo-contributions.md) | Module SEO contributions: sitemap, `llms.txt` and JSON-LD | Accepted |
| [0061](0061-scheduled-jobs-for-modules.md) | Scheduled jobs for modules | Accepted |
| [0062](0062-route-permissions-and-critical-routes.md) | Route permissions and critical routes | Accepted |
| [0063](0063-sms-provider-contract.md) | Text message (SMS) provider contract | Accepted |
| [0064](0064-block-variants-options-and-icons.md) | Block variants, shared options and icons in the module contract | Accepted |
| [0065](0065-external-module-failures.md) | A broken external module doesn't stop the tenant | Accepted |
| [0066](0066-custom-roles-screen.md) | Custom roles on the roles screen and the command line | Accepted |
| [0067](0067-tenant-domain-and-email-policies.md) | Tenant domain and email policies | Accepted |
| [0068](0068-domain-registration.md) | Domain registration on members-only sites | Accepted |
| [0069](0069-text-messages.md) | Text messages (SMS module) | Accepted |
| [0070](0070-phone-numbers.md) | Phone numbers on accounts | Accepted |
| [0071](0071-text-notifications.md) | Text notifications and the outbox | Accepted |
| [0072](0072-phone-sign-in.md) | Signing in and resetting the password with a mobile number | Accepted |
| [0073](0073-two-step-confirmation.md) | Two-step confirmation for important changes | Accepted |
| [0074](0074-embed-page-list-sign-language-video.md) | The embed, page list and sign language video blocks | Accepted |

When an ADR's status changes, update it here too.
