# 0023 — Search, dashboard and AI assistant contracts (`module-interface` 0.4)

- Status: Proposed
- Date: 2026-11-17

## Context

M2 adds site search (ADR-0011), analytics with a dashboard widget (ADR-0012) and an optional AI assistant
(ADR-0010). Each of them needs extension points that external modules can use exactly like core's own modules
(ADR-0009): modules must be able to make their content searchable or replace the search back-end, add dashboard
widgets, and add assistant providers and tasks. These are changes to the public contract, so they need an ADR.

`ModuleManifest::$enabledByDefault` already exists since `module-interface` 0.1 (default `false`, ignored for
required modules), so the Features screen (F-2) needs no manifest change.

## Decision

Ship both groups in one additive minor release, `sightpress/module-interface` `0.4.0`.

### Search (`SightPress\Module\Search`)

- `SearchDocument`: source, ref, locale (BCP 47), site-relative path, title, `updatedAt` (stored in UTC), then
  optional summary, body, breadcrumb and `SearchVisibility` (`public` or `members`); `key()` = `source:ref:locale`.
  Plain text only, published content only.
- `SearchDocumentProvider` (implemented by modules): `source()`, `documents(locale)` (iterable, so big sites can
  stream) and `document(ref, locale)` (null when it is gone, unpublished or left out of search).
- `SearchBackend` (implemented by core's built-in back-end and by modules that replace it): `index()`,
  `remove(source, ref, ?locale)`, `clear(?locale)`, `query(SearchQuery): SearchResults` and `status()` (a
  `SearchIndexStatus` per locale, for `site:search:status`).
- `SearchQuery`: text (at most 200 characters), locale, `includeMembersOnly`, page, per page (1–50).
  `SearchHit`: document, score, matched words (for extracts with `<mark>`). `SearchResults` refuses hits in
  another locale and members-only hits for anonymous queries, so a back-end bug can't leak a members page, and
  says whether every word matched (`matchedAllTerms`) or the results fell back to any word.
- `SearchPerformed` event: the normalised query (trimmed, lower-cased, white space collapsed), locale and result
  count; nothing about who searched.
- `ModuleRegistrar::search()`: `addProvider(class-string)` and `useBackend(class-string)`. At most one module may
  replace the back-end; a second one stops the boot with an error naming both.

### Dashboard (`SightPress\Module\Dashboard`)

- `DashboardWidget` (implemented by modules): `id()`, `title()` (core renders it as the `<h2>` of a `<section>`),
  `permission()` (null = every admin user), `position()` and `render(DashboardContext): string` for the content.
- `DashboardContext` (implemented by core): `locale()`, `timeZone()`, `can()` and `url()` for named routes.
- `ModuleRegistrar::dashboard()`: `add(class-string)`.

### AI assistant (`SightPress\Module\Assistant`)

- `AssistantProvider` (implemented by core's adapters and by modules): `id()`, `label()`, `description()`
  (plain-language privacy facts shown before an admin chooses it), `models()`, `needsBaseUrl()`, per-model
  `capabilities()` (`ProviderCapabilities`: vision, streaming) and
  `complete(AssistantRequest, AssistantConnection): AssistantResponse`.
- Streaming is optional: `StreamingAssistantProvider extends AssistantProvider` adds `stream()`, a generator of
  text pieces that returns the whole `AssistantResponse`. Keeping it in its own interface means providers that
  can't stream don't need a method that throws.
- The key travels in `AssistantConnection` (key + optional base URL), separate from the request, so requests and
  responses can be passed around and tested without a secret. The key is a `#[SensitiveParameter]`, hidden from
  `var_dump()`/`print_r()` (`maskedKey()` gives `••••1234`), and the object refuses to be serialised.
- `AssistantRequest`: model, messages (`AssistantMessage` with `AssistantRole` system/user/assistant; system
  messages first, the last one from the user; `AssistantImage`s only on user messages), most output tokens and an
  optional temperature 0–1. `AssistantResponse`: text, model, `TokenUsage`, `truncated`.
- `AssistantTask` (implemented by core and modules): `id()`, `label()`, `permission()`, `inputSchema()`,
  `needsVision()`, `buildPrompt(input, AssistantContext): list<AssistantMessage>` and
  `validateOutput(response, input, context): AssistantSuggestion`. The only output type is a suggestion (text +
  form fields for *Insert*); there is no way to change the site from a task (ADR-0010).
- `AssistantContext` (implemented by core): `locale()` of the answer, `can()` and `screen()`.
- Failures are `Exception\AssistantException` with an `AssistantFailure` (invalid key, quota exceeded, rate
  limited, timeout, model unavailable, refused, invalid output, unavailable), so core can show one plain-language
  message per case for every provider; exception messages never contain the key, prompt or answer.
- `ModuleRegistrar::assistant()`: `addProvider(class-string)` and `addTask(class-string)`.

### Core

- `core` requires `sightpress/module-interface` `^0.4`. Its registrar records search providers, the search
  back-end, dashboard widgets, assistant providers and tasks per module in the `ModuleRegistry` (with the usual
  duplicate and type checks); nothing uses them until SR-1, AN-3 and A-2.

## Consequences

- Modules that implement only the 0.3 contracts keep working: every addition is a new type or a new method on an
  interface that only core implements.
- Interfaces that modules implement (`SearchDocumentProvider`, `SearchBackend`, `DashboardWidget`,
  `AssistantProvider`, `StreamingAssistantProvider`, `AssistantTask`) can only change in a major release, so they
  include now what SR-1, AN-3 and A-2 … A-6 are known to need (`clear()`, `status()`, `needsBaseUrl()`,
  `needsVision()`). A contract shape test in `module-interface` pins constructor arguments and interface methods.
- `SearchResults` checks locale and visibility of every hit, which costs a loop per page of results and turns a
  back-end bug into an error instead of a leak.
