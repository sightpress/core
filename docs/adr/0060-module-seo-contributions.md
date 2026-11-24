# 0060 — Module SEO contributions: sitemap, `llms.txt` and JSON-LD

- Status: Proposed
- Date: 2026-11-24

## Context

Core builds `sitemap.xml`, `llms.txt` and each page's JSON-LD from its own pages (ADR-0025, `SeoFiles`, `SeoHead`).
A module that serves public pages (events, for example) has no way to be listed or described.

## Decision

- Add `Seo\SeoEntry`: path, locale, title, `updatedAt` (UTC), optional description, Markdown path (`.md`),
  alternates (locale → path) and schema.org nodes (each with an `@type`; core adds `@context`).
- Add `Seo\SeoProvider::entries(locale)` (implemented by modules) and a `seo()` registrar (`addProvider()`).
  Providers list only public, indexable pages.
- Use the same `SeoEntry` for a page's head: `Http\PublicScreen::$seo` (ADR-0059). Core writes the description,
  canonical address, `hreflang` and Markdown links and adds the nodes to the page's JSON-LD graph.
- Core leaves every module entry out on members-only sites, on installs not in production, and on sites where
  the module is off, and clears its SEO cache when a module is turned on or off. Wiring is task X-1.
- `llms-full.txt` stays limited to core's pages: a module's Markdown is linked from `llms.txt`, not inlined.

## Consequences

- Module pages are found by search engines and AI readers the same way as core pages.
- Providers are called only when the cached files are rebuilt, so they may query the database.
