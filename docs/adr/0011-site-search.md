# 0011 — Site search

- Status: Proposed
- Date: 2026-11-13

## Context

Visitors need to search a site's pages, in its languages, without the site owner running a search server. Our
portability rule forbids engine-specific SQL (no `FULLTEXT`, no `tsvector`), so the database's own full-text
features are out. Search results must never show a page the visitor isn't allowed to open, and some installs will
later want a dedicated search server.

## Decision

- Build search as an optional module (`search`) that is on by default for new sites.
- Define the search contracts in `module-interface`: a `SearchDocument` value object, `SearchDocumentProvider`
  (modules supply documents; pages are the first provider) and `SearchBackend` (`index`, `remove`, `query`), plus
  a `search()` sub-registrar. A module can replace the back-end.
- Ship a built-in back-end that keeps an inverted index in the site database, built only with the DBAL
  QueryBuilder and Schema API: one table of documents (one row per document and locale) and one of postings
  (term, document, field, frequency).
- Analyse text per locale in PHP: Unicode normalisation and case folding, accents removed, word splitting with ICU
  (which covers scripts without spaces), stemming where a stemmer exists, and stop words.
- Score in PHP (BM25F, with title weighted above summary above body). Require all terms, then fall back to any
  term and say so on the results page. Limit query length and the candidate set.
- Index only published content, after the transaction commits, from workflow and media events.
- Store each document's visibility and filter results for the viewer: anonymous visitors get public documents;
  signed-in users also get the members-only documents they may open. Search one locale at a time.
- Let editors leave a page out of site search, separately from `noindex`.
- Results pages are `noindex, follow`, have no canonical URL, and are rate-limited. Core search doesn't store
  queries; it fires an event that other modules (analytics) can listen to.

## Consequences

- Search works on every supported database with no extra service.
- Relevance and speed are good enough for small and medium sites; a performance budget is checked on a large
  fixture, and big sites can plug in another back-end.
- The index takes space in the site database and needs a reindex command.
- Visibility is checked at query time, so changing a page's visibility must re-index it.
