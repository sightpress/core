# Changelog

## [0.4.0](https://github.com/sightpress/core/compare/0.3.0...0.4.0) (2026-11-20)


### Features

* add the Appearance screen to choose the site's theme and change its colours, and theme:publish-assets for its previews (T-2) ([#95](https://github.com/sightpress/core/issues/95)) ([e2dc9b2](https://github.com/sightpress/core/commit/e2dc9b258da4909de5c0eaca363a76fb459a995e))
* add theme:preview, a static, offline preview page of a theme made from theme-interface's sample page (T-5) ([#89](https://github.com/sightpress/core/issues/89)) ([88a678c](https://github.com/sightpress/core/commit/88a678c380ad17ad583603e6aeda38fa88183055))
* find the themes installed with Composer, check them, and let each site use its own, with theme:list, theme:validate, site:theme:apply and cache:clear (T-1) ([#94](https://github.com/sightpress/core/issues/94)) ([94da8ad](https://github.com/sightpress/core/commit/94da8ad87356a56c2f10255613ea2f933f70eaeb))
* let editors use the landing, sidebar and full-width layouts, list a section's pages beside a sidebar page, and say which pages lose their layout before a theme change (T-3) ([#96](https://github.com/sightpress/core/issues/96)) ([4172ba9](https://github.com/sightpress/core/commit/4172ba917aef16ab8e74784981c6d30ec5f21535))
* take the default theme from the sightpress/theme-default package and keep no theme files in core (T-0) ([#93](https://github.com/sightpress/core/issues/93)) ([a7aa7ce](https://github.com/sightpress/core/commit/a7aa7ce2a5c29212a19f9d4fd80412423d188369))

## [0.3.0](https://github.com/sightpress/core/compare/0.2.0...0.3.0) (2026-11-19)


### Features

* add an Analytics screen with accessible charts, tables and CSV downloads, and a Last 7 days dashboard box (AN-3) ([#79](https://github.com/sightpress/core/issues/79)) ([3873a95](https://github.com/sightpress/core/commit/3873a9526afc938110f4b3e418b5d5145dd7598a))
* add the AI assistant screen, an Ask the assistant panel on the dashboard and page editor, and Suggest buttons for the summary and search fields (A-3) ([#81](https://github.com/sightpress/core/issues/81)) ([e841c9d](https://github.com/sightpress/core/commit/e841c9db52c61424c26a73a5c5a63d424d28a539))
* add the assistant's drafting tasks (summary, search title and description, rewrite, draft a page, explain a check, alt text) with checked answers, and alt text that needs a person to check it (A-4) ([#82](https://github.com/sightpress/core/issues/82)) ([7e90f0f](https://github.com/sightpress/core/commit/7e90f0f6616dde984caa5528f5898287d393d101))
* add the optional AI assistant module with Anthropic, OpenAI, Mistral and OpenAI-compatible services, encrypted keys, a monthly budget and site:assistant commands (A-2) ([#80](https://github.com/sightpress/core/issues/80)) ([5f3af42](https://github.com/sightpress/core/commit/5f3af42b165df4e443e8988a4b77c6ea46e9c540))
* answer questions about SightPress from a help index of the documentation, pointing only to screens the person can use (A-5) ([#83](https://github.com/sightpress/core/issues/83)) ([e6c554e](https://github.com/sightpress/core/commit/e6c554e02ea1cba6549c79b62c06179e72f7227c))
* count visits, pages, sources, searches and bots on the server without cookies or personal data, with site:analytics commands (AN-1) ([#77](https://github.com/sightpress/core/issues/77)) ([aba2814](https://github.com/sightpress/core/commit/aba28140ec66a5f7047f2585df498b92b6958c77))
* draw accessible charts on the server as SVG, with a summary in words, a key and the data as a table (AN-2) ([#78](https://github.com/sightpress/core/issues/78)) ([7eb9240](https://github.com/sightpress/core/commit/7eb9240b7b050ac72290701a47ae58019cb98a1e))
* give the demo content translations, a members-only page, a page in the trash and a redirect, and crawl and walk through every M2 feature (Q-1, Q-2) ([#86](https://github.com/sightpress/core/issues/86)) ([18e1590](https://github.com/sightpress/core/commit/18e1590e7d732dc56c1514619ee5b75373179a9a))
* let the assistant draft a translation that keeps the page's blocks and links, marked until a person checks it (A-6) ([#84](https://github.com/sightpress/core/issues/84)) ([7daaefd](https://github.com/sightpress/core/commit/7daaefd9e41c022f0febf1a98427c2f8910e73e5))
* show the largest file that can be uploaded, warn admins about server settings that stop uploads, and explain a request that is too large (M-5) ([#75](https://github.com/sightpress/core/issues/75)) ([932a059](https://github.com/sightpress/core/commit/932a0595bc2fcf16d343ae9622ff5d99d639e3be))


### Documentation

* accept the M2 decision records and mark the reviewed pt-PT and es texts (Q-5) ([#85](https://github.com/sightpress/core/issues/85)) ([9b7260e](https://github.com/sightpress/core/commit/9b7260e336f0bce363bdc7f99bb72a0991dd9cf0))

## [0.2.0](https://github.com/sightpress/core/compare/0.1.0...0.2.0) (2026-11-19)


### Features

* change your password, email address and admin preferences on My account (U-3) ([#70](https://github.com/sightpress/core/issues/70)) ([ce3f588](https://github.com/sightpress/core/commit/ce3f588b61cf8f61c05c248f2d0a9ca9804be37e))
* copy, archive, trash and move pages, with redirects that keep old addresses working (P-1) ([#63](https://github.com/sightpress/core/issues/63)) ([2546d6f](https://github.com/sightpress/core/commit/2546d6fda932a533780c2612d2ef3a43d61cd3e1))
* give menu entries their text in every language, members-only entries and links that follow moved pages (MN-1) ([#69](https://github.com/sightpress/core/issues/69)) ([844cef0](https://github.com/sightpress/core/commit/844cef04b0bfd9c45c0308914d0cdcb41fd2b9dd))
* index the site's published pages for site search, kept up to date as pages and media change, with site:search commands (SR-1) ([#72](https://github.com/sightpress/core/issues/72)) ([734e683](https://github.com/sightpress/core/commit/734e6837ca7cb857612d9c8edef5854c9d901c1a))
* let admins choose a site's languages and translate pages as drafts of their own (I-1) ([#58](https://github.com/sightpress/core/issues/58)) ([dad846a](https://github.com/sightpress/core/commit/dad846a2511a688fee61fee6b5a7d32f774f49ff))
* let admins turn optional features on or off per site, keeping their data, on a Features screen and with site:module commands (F-2) ([#71](https://github.com/sightpress/core/issues/71)) ([506d355](https://github.com/sightpress/core/commit/506d355033354498e219a4c8b14ed1033bc9a911))
* let people create their own account, confirmed with an emailed code, when the site allows it (U-1) ([#67](https://github.com/sightpress/core/issues/67)) ([5939980](https://github.com/sightpress/core/commit/593998039e75d8886b3c67436c6aed58277657d3))
* make a whole site or single pages readable by signed-in members only, with sign-in and back (U-2) ([#68](https://github.com/sightpress/core/issues/68)) ([0a9f562](https://github.com/sightpress/core/commit/0a9f562993702c64822f3bee87ffa65c9d04f38d))
* publish and unpublish pages at a set time with one schedule:run that other modules add jobs to (P-3) ([#66](https://github.com/sightpress/core/issues/66)) ([04bf60b](https://github.com/sightpress/core/commit/04bf60bac79cc2db7dddb116b4a1d9ef5766b608))
* put a search form on every page and show the results with marked extracts, in every language (SR-2) ([#73](https://github.com/sightpress/core/issues/73)) ([33b6d5a](https://github.com/sightpress/core/commit/33b6d5a44066974fd3ee67d2920a81cca5902e0a))
* require module-interface 0.4 and record search, dashboard and assistant registrations (MI-0.4) ([#56](https://github.com/sightpress/core/issues/56)) ([c83fffe](https://github.com/sightpress/core/commit/c83fffe6d7954344e909f2d094ea201d268300ec))
* route every language with fallbacks, reciprocal hreflang and a sitemap per language (I-2) ([#59](https://github.com/sightpress/core/issues/59)) ([168a2a4](https://github.com/sightpress/core/commit/168a2a4c3ffc55d09168a2b67caf0e5ab7b36e4e))
* show the older versions of a page per language, compare them word by word and restore one without publishing (P-2) ([#65](https://github.com/sightpress/core/issues/65)) ([a87a2e4](https://github.com/sightpress/core/commit/a87a2e4c83d8ba3ff5784f5aeca08f526559fd15))
* translate the admin panel, site texts and emails, starting with European Portuguese and Spanish (I-3) ([#60](https://github.com/sightpress/core/issues/60)) ([c817ffc](https://github.com/sightpress/core/commit/c817ffc11e33fe9f8ba9936aab5f415fe1bae738))
* write the starter pages in every language of a new site, and as drafts for a language added later (I-4) ([#61](https://github.com/sightpress/core/issues/61)) ([812bf61](https://github.com/sightpress/core/commit/812bf610ee6797bdd1e5d7d47d6697e95b1b66e6))


### Documentation

* add a code of conduct and a contributing guide ([#74](https://github.com/sightpress/core/issues/74)) ([c40ad07](https://github.com/sightpress/core/commit/c40ad07b5817233ca0583f55c05cb862b3707621))
* record the pt-PT and es catalogue review before 0.2.0 ([#62](https://github.com/sightpress/core/issues/62)) ([14f9dfd](https://github.com/sightpress/core/commit/14f9dfd92e30af0c18bc4b83bde93ae613b5aa2f))

## 0.1.0 (2026-11-17)


### Features

* add content blocks with schema checks, sanitised rich text and five core types (C-4.2) ([#31](https://github.com/sightpress/core/issues/31)) ([8364828](https://github.com/sightpress/core/commit/8364828f172c3ed1f6b344dd30b40d948a53d47c))
* add gallery, video, audio, file download and contact form blocks (C-4.3) ([#32](https://github.com/sightpress/core/issues/32)) ([3b12079](https://github.com/sightpress/core/commit/3b120794a3ac50ad899f427cef520231e0710fa5))
* add My account with avatars, time zones and languages (C-3.6) ([#36](https://github.com/sightpress/core/issues/36)) ([2e2998a](https://github.com/sightpress/core/commit/2e2998ada866779470dbf106fe6a5842b3c20f68))
* add security headers, sessions, CSRF checks and rate limits (C-1.2) ([#14](https://github.com/sightpress/core/issues/14)) ([f987c18](https://github.com/sightpress/core/commit/f987c183cfaffc54a454440aa5565026deaa67cb))
* add the admin media library with announced upload progress (C-5.3) ([#39](https://github.com/sightpress/core/issues/39)) ([a57c883](https://github.com/sightpress/core/commit/a57c883a81ce7ef41ef3e629a61641041d86896d))
* add the admin shell with permission-driven navigation and a dashboard (C-7.1) ([#35](https://github.com/sightpress/core/issues/35)) ([fd89846](https://github.com/sightpress/core/commit/fd89846cc328babdd91af9e90489a548801f3c6b))
* add the default theme with design tokens, layouts and accessible partials (C-6.1) ([#26](https://github.com/sightpress/core/issues/26)) ([897d652](https://github.com/sightpress/core/commit/897d652fe128f6a7def7de64a65b201ce90a5cc0))
* add the main and footer menus, breadcrumbs and the menu editor (C-6.3) ([#41](https://github.com/sightpress/core/issues/41)) ([490322b](https://github.com/sightpress/core/commit/490322b8fe6d8df237023fd19ce84842034ed434))
* add the page list and the page editor with its block editor, search & AI fields and workflow buttons (C-7.2) ([#47](https://github.com/sightpress/core/issues/47)) ([debe3ce](https://github.com/sightpress/core/commit/debe3ce6867adea9263d3c5a98d1e2258a1b8bfd))
* add the page tree with translations, addresses and revisions (C-4.1) ([#30](https://github.com/sightpress/core/issues/30)) ([8de9ffb](https://github.com/sightpress/core/commit/8de9ffba6f70a0e929a1f7e33480e59051b301c1))
* add the site settings and branding screens, with the owner's profile, search console codes and IndexNow (C-7.3) ([#48](https://github.com/sightpress/core/issues/48)) ([95534c9](https://github.com/sightpress/core/commit/95534c95d333a19b3016d138c6207b9acf7e3cc4))
* add users, roles and the sign-up guardrail as core modules (C-3.1) ([#20](https://github.com/sightpress/core/issues/20)) ([ceb4b9b](https://github.com/sightpress/core/commit/ceb4b9ba18fd01be5c17764fe2358f0cc14463cc))
* ask guided questions in console commands, starting with site:create ([#52](https://github.com/sightpress/core/issues/52)) ([96eaa3d](https://github.com/sightpress/core/commit/96eaa3d20c5f8d726746ba8e76fb6cbcf11dc3dd))
* boot the kernel from the tenant config, with accessible error pages (C-1.1) ([#12](https://github.com/sightpress/core/issues/12)) ([fffe2cf](https://github.com/sightpress/core/commit/fffe2cf5c318cb35d4739184a6df4520dee578f2))
* build accessible forms with server-side validation (C-1.4) ([#16](https://github.com/sightpress/core/issues/16)) ([404d707](https://github.com/sightpress/core/commit/404d7075d610d73a5e637adfcbbc41c3717082f6))
* check pages for accessibility and search problems before they are published (C-4.5) ([#34](https://github.com/sightpress/core/issues/34)) ([154d6f5](https://github.com/sightpress/core/commit/154d6f5a80c1abcdaea396b086d50a1c63f51a97))
* check theme contrast and base font size with theme:validate (C-6.5) ([#27](https://github.com/sightpress/core/issues/27)) ([56bc694](https://github.com/sightpress/core/commit/56bc69498c723f59998b4d6db6eee180466748a7))
* crawl a demo site for accessibility, search engines and AI readers as every role (C-8.1) ([#50](https://github.com/sightpress/core/issues/50)) ([a1a7644](https://github.com/sightpress/core/commit/a1a7644067e61c7654f130136c12f9bb509655e5))
* create sites from the console and manage their status (C-2.3) ([#23](https://github.com/sightpress/core/issues/23)) ([f73f91e](https://github.com/sightpress/core/commit/f73f91e936361b20965282053b34a601b6b32b12))
* decide access with an authorizer, page voter and admin door (C-3.2) ([#21](https://github.com/sightpress/core/issues/21)) ([ce22d22](https://github.com/sightpress/core/commit/ce22d22c591c0bf54fc1f92de6460162e73e371a))
* give every new site published starter pages and menus (C-6.4) ([#42](https://github.com/sightpress/core/issues/42)) ([3887e48](https://github.com/sightpress/core/commit/3887e48d3f8175694b3d81bcf340f5d386c9ccf0))
* give every public page its search engine title, description, canonical address and graph, plus sitemap.xml and robots.txt (C-6.7) ([#44](https://github.com/sightpress/core/issues/44)) ([82788f1](https://github.com/sightpress/core/commit/82788f1f957ef6a10ffe1164ddc2ddd437a5271f))
* give every site a logo, icon set, share image and brand tags in the head (C-6.6) ([#43](https://github.com/sightpress/core/issues/43)) ([1c76eff](https://github.com/sightpress/core/commit/1c76eff9560376b7d458d1d26a022c8c6d18ac61))
* give every site a password policy its admin can change, shown in the password hints (C-3.8) ([#53](https://github.com/sightpress/core/issues/53)) ([f7e1dfe](https://github.com/sightpress/core/commit/f7e1dfe733cac7d875c6f4aef84018a6fee59821))
* invite people by email with a role, and let them set up their account (C-3.4) ([#37](https://github.com/sightpress/core/issues/37)) ([e6b7f6d](https://github.com/sightpress/core/commit/e6b7f6d2267fbcb9adae37a6e7adff6c6d0ebb49))
* keep the tenant's sites in a validated registry file with encrypted DSNs (C-2.1) ([#18](https://github.com/sightpress/core/issues/18)) ([9e870d6](https://github.com/sightpress/core/commit/9e870d6707775f44386f228277028d8562a752cd))
* list, sort and manage the people of a site in the admin (C-3.5) ([#38](https://github.com/sightpress/core/issues/38)) ([f7891a4](https://github.com/sightpress/core/commit/f7891a46d213ac168e4257a83d2ddb0e9bf13463))
* load modules and boot the kernel only through them (C-1.5) ([#13](https://github.com/sightpress/core/issues/13)) ([5e06e3f](https://github.com/sightpress/core/commit/5e06e3faffff594cb4cf36a1d6b96127b4f3c29e))
* move a disabled site to another database (C-2.4) ([#24](https://github.com/sightpress/core/issues/24)) ([f8f5bd1](https://github.com/sightpress/core/commit/f8f5bd11b5da6a9de28d3bb4492e7abdda95a4f3))
* move pages through review and publishing, and ask editors to review (C-4.4) ([#33](https://github.com/sightpress/core/issues/33)) ([e5f45c8](https://github.com/sightpress/core/commit/e5f45c8c947b33cf881504aead03dedb27a204a2))
* offer every public page to AI readers as Markdown and in llms.txt, with an AI crawler policy (C-6.8) ([#45](https://github.com/sightpress/core/issues/45)) ([98c9845](https://github.com/sightpress/core/commit/98c9845369a2abec11830e50da7c4d996082d2e5))
* record who changed what in an append-only audit log, with filters and CSV export (C-7.4) ([#49](https://github.com/sightpress/core/issues/49)) ([1a3b75a](https://github.com/sightpress/core/commit/1a3b75a6d1732b74655255847627de1b50625514))
* render templates with a theme-aware loader and core's helpers (C-1.3) ([#15](https://github.com/sightpress/core/issues/15)) ([1017933](https://github.com/sightpress/core/commit/10179338a61863cf62c6284ded38d8aea70ffcc0))
* resolve the site from the host and give each site its own container (C-2.2) ([#19](https://github.com/sightpress/core/issues/19)) ([266eeb3](https://github.com/sightpress/core/commit/266eeb36b01e9df313ecd4d7fe2c61548e74b9f1))
* send six-digit codes instead of links for password reset, invitations and the first admin (C-3.7) ([#46](https://github.com/sightpress/core/issues/46)) ([9265d40](https://github.com/sightpress/core/commit/9265d409ea6687c1e07f9ac80f14a45dcc05d938))
* serve each site's media with caching, ranges and access checks (C-5.2) ([#29](https://github.com/sightpress/core/issues/29)) ([1bad923](https://github.com/sightpress/core/commit/1bad923646e040348a148303807ef6c38c0633f8))
* serve the public site with the home page at / and pages at their address (C-6.2) ([#40](https://github.com/sightpress/core/issues/40)) ([5e2d817](https://github.com/sightpress/core/commit/5e2d817628f9eacfbbdb06f05c5dd724b58aafee))
* sign in and out, keep me signed in and reset passwords (C-3.3) ([#22](https://github.com/sightpress/core/issues/22)) ([492f1b7](https://github.com/sightpress/core/commit/492f1b71cf09f354abda75a64d985d1cd906b328))
* store each site's media and check, clean and dedupe uploads (C-5.1) ([#28](https://github.com/sightpress/core/issues/28)) ([5f27224](https://github.com/sightpress/core/commit/5f27224c8a9930a670202c3e84ca9dc85e8b8d6e))


### Documentation

* accept ADR-0014, permissions say whether sign-up roles may hold them (MI-0.2) ([#11](https://github.com/sightpress/core/issues/11)) ([873775e](https://github.com/sightpress/core/commit/873775e239a2479b4f8dd4895071a205ff0a4198))
* accept ADRs 0001-0013; tenant registry as a config file (S-5) ([#10](https://github.com/sightpress/core/issues/10)) ([92f9a42](https://github.com/sightpress/core/commit/92f9a4209820d226549a3216bed69a3f1ed214b5))
* accept ADRs 0015-0022 (M1) ([#54](https://github.com/sightpress/core/issues/54)) ([974a6f2](https://github.com/sightpress/core/commit/974a6f2c14dfc2dd6d548fd4c9d2a6ff0de1c6bf))
* add the ADR template and ADRs 0001-0013 (S-5) ([#2](https://github.com/sightpress/core/issues/2)) ([ecdfcc8](https://github.com/sightpress/core/commit/ecdfcc87c036937d4d75768e8ee0d56f7bd80a53))
* align ADR-0009 with module-interface 0.1 (S-7) ([#4](https://github.com/sightpress/core/issues/4)) ([ed1e349](https://github.com/sightpress/core/commit/ed1e3493fce9b97c3309f7eb6e247912d9b5cb39))
