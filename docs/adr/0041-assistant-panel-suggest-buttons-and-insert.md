# 0041 — AI assistant: the settings screen, the panel, Suggest buttons and Insert without JavaScript

- Status: Proposed
- Date: 2026-11-20

## Context

A-2 (ADR-0040) built the assistant without a screen. A-3 adds the AI assistant screen, "Ask the assistant" on the
dashboard and the page editor, and Suggest buttons under the page's summary, title in search results and
description. The dashboard (admin module) and the page editor (page admin module) may not depend on the optional
assistant module (ADR-0009), and every screen has to work without JavaScript.

## Decision

- **`Infrastructure\Assistant\AssistantAccess`** (shared code, in every site container) says whether a screen shows
  the panel and which Suggest buttons: only while the assistant module is on, it is set up, the person may use it
  and the field's task is there (`summary`, `seo_meta`, `alt_text`). The screens include `@assistant/panel.html.twig`
  and `@assistant/suggest.html.twig` only then, so with the module off nothing of it is on the page and its routes
  answer 404 (F-2).
- **The assistant reads the saved page**, through the page module's contract `Page\Contract\PageTexts`, and only a
  page the person may edit. Nothing typed but not yet saved is sent; the panel says so. Sharing is opt-in ("Share
  this page with the assistant") for questions, and implied by a Suggest button.
- **Suggest buttons sit inside the editor's form** but send an empty form in the panel (`form="assistant-suggest"`),
  so they never submit or save the page.
- **Without JavaScript** the forms go to a result page: the suggestion under the `<h1>`, then Insert, Try again and
  Discard. Insert keeps the suggestion in the session for the screen it came from (`Assistant\Contract\
  AssistantInsert`); the editor puts it in the field, unsaved, once, and the person lands at the field.
- **With JavaScript** (`js/assistant.js`, `X-Requested-With: XMLHttpRequest`) the answer is JSON. "Working on it…",
  "Suggestion ready" and short confirmations go to the panel's polite status region, errors to its alert region; the
  suggestion goes under its own heading, which gets focus, and never into a live region. Insert fills the field and
  moves focus back to it; Discard moves focus back to where the person asked.
- **The settings screen** (`/admin/assistant`, `assistant.manage`, under Site settings): status in words, services
  as radios with their descriptions, models grouped by service (`provider::model` values), a typed model and the
  address for services without a list, a write-only key (`••••1234`, Remove key), Test connection (its result in a
  live region), the roles and the monthly budget with this month's use, and what is sent and kept. Turning the
  assistant on and off stays on the Features screen.

## Consequences

- Suggestions for a page that was never saved in that language aren't possible: the person saves first.
- Modules can add screens with the panel by calling `AssistantAccess::panel()` and including the two templates.
- The A-4 drafting tasks must accept the Suggest buttons' input: `field`, `title`, `summary`, `text`.
