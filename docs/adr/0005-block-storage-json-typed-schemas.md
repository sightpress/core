# 0005 — Block storage as JSON with typed schemas

- Status: Accepted
- Date: 2026-11-13

## Context

Page content is an ordered list of blocks (heading, rich text, image, video, call to action, columns …). Modules can
add new block types. Each type has different fields, and pages have one block list per locale. We also render each
block to HTML, to Markdown (for AI readers) and optionally to schema.org JSON-LD, and we check each block against
accessibility rules before publishing.

A table per block type would need a migration for every new type and many joins per page. Raw HTML would lose the
structure we need for checks, Markdown and translations.

## Decision

- Store a page translation's blocks as one JSON document: an ordered array of
  `{ "type": "<name>", "version": <int>, "data": { … } }`. Write it through Doctrine's JSON type so it works on
  every supported engine.
- Define every block type with the `BlockType` contract from `sightpress/module-interface`: a unique name, a
  schema for its data, `render()`, `renderMarkdown()`, `structuredData()` and `a11yRules()`.
- Validate block data against the type's schema on every save, on import and on anything suggested by the AI
  assistant. Unknown types and invalid data are refused, not stored.
- Sanitise rich text with an allow-list HTML sanitiser on save, and escape everything else at render time.
- Keep a `version` per block so a type can migrate old data when its schema changes.
- Reference media by id inside block data, never by URL, so URLs can change (origin vs CDN).
- Don't query inside block JSON. Anything that must be searched or filtered (title, slug, summary, status,
  search text) lives in its own columns or tables.

## Consequences

- Adding a block type needs no database migration.
- Revisions are simple: a snapshot of the JSON.
- Finding every page that uses a media item needs a separate usage index, kept up to date on save.
- Schema changes for a block type need a data upgrade step tied to its `version`.
