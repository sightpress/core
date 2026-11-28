# 0078 — Placeholders contract for modules

- Status: Proposed
- Date: 2026-11-27

## Context

BL-6 adds placeholders to text fields (`blocks.md` §5): `{{ site.name }}` with an optional fallback, picked from an
accessible *Insert detail* menu (syntax decided in stage-5 gap 9). Core supplies `site.*` and, on members-only pages
only, `user.*`. Modules need to add their own (an events module: the next event's date).

## Decision

- Add `ModuleRegistrar::placeholders(): PlaceholderRegistrar` with `add(Placeholder)`.
- `Placeholder\Placeholder`: a dotted name of at least two parts (`acme_events.next_date`), label and description
  (for the menu), a `PlaceholderType` (text, number, date, date and time, web address, email, phone), whether it is
  **personal**, and the `PlaceholderResolver` class that gives its value.
- `PlaceholderResolver::resolve(name, PlaceholderContext)` returns plain text, a number, a date or null (no value).
  It never returns HTML: core escapes the value for the place it goes (HTML, attribute, URL, Markdown) and formats
  numbers and dates with `LocaleFormats` (ADR-0080).
- `PlaceholderContext` (core-implemented): the content locale and, for personal placeholders on members-only pages
  only, the viewer's account id.
- Core keeps `site.*` and `user.*` for itself, refuses unknown names when a page is saved, resolves personal
  placeholders only on members-only pages (the page becomes `private, no-store`) and never puts them in Markdown,
  `llms.txt`, JSON-LD or the search index.

## Consequences

- BL-6 builds the menu, the save check and the rendering on this; a module's placeholders appear only on sites where
  the module is on.
- A resolver that throws makes the placeholder show its fallback, and core logs the module's name, not the value.
