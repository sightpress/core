# 0035 — Site search: an index in the site database, kept up to date by events

- Status: Accepted
- Date: 2026-11-19

## Context

ADR-0011 chose site search as an optional module with its own inverted index in the site database, analysis and
scoring in PHP, and only portable DBAL code. ADR-0023 added the search contracts to `module-interface` 0.4. SR-1
builds it. Some choices weren't settled there: where the page documents come from (the search module may only use
other modules' contracts), how the module reaches the providers and the back-end other modules registered (modules
may not use the module loader), what to do when a change touches every document, and how to keep the index correct
on MySQL, whose default collation is case- and accent-insensitive.

## Decision

- **New runtime dependency:** `wamania/php-stemmer` `^4` (Snowball stemmers in PHP for Catalan, Danish, Dutch,
  English, Finnish, French, German, Italian, Norwegian, Portuguese, Romanian, Russian, Spanish and Swedish; MIT). It
  brings `joomla/string`. `ext-intl` was already required; ICU splits the words, including Chinese, Japanese and
  Thai, which are written without spaces.
- **Analysis** (`TextAnalyzer`, one per locale): NFKC; case folding; invisible marks (direction marks, zero-width
  joiners, Arabic tatweel) dropped; curly apostrophes made straight; accents and other combining marks removed from
  Latin, Greek, Cyrillic, Hebrew and Arabic letters only (never from Thai, Indic scripts or Japanese kana, where they
  change the word); elided articles dropped in French, Italian and Catalan (`l'histoire` → `histoire`); stop words
  from `resources/search/stopwords/<language>.txt` (en, pt, es, fr, de, it, nl); then the stemmer, which sees the
  word without accents, so a query typed without accents finds the same terms.
- **Tables** (search module migration): `search_document` (one row per source, ref and locale, with the text kept
  for results, who may find it, and the number of terms in the title, summary and body), `search_posting` (term,
  document, part, frequency) and `search_word` (the words of each locale and their terms). `search_word` lets the
  last word of a query also find longer words: "accessib" finds "accessibility", whose stem is "access". Postings
  and words have surrogate keys and plain indexes, not unique keys on the term: on MySQL's default collation two
  different terms (for example Japanese か and が) compare equal.
- **Scoring** (`Bm25f`): BM25F with title 3×, summary 2×, body 1×, `k1` 1.2 and `b` 0.75; the average length of a
  part is taken over the documents that have it (most pages have no summary). The last word and the longer words it
  starts count once, with the best of them. Newer documents get up to 10 % more, halving every six months. A query
  uses at most 10 distinct words and reads at most 50,000 postings. A page of results after the last one gives the
  last one (`SearchResults` only accepts pages that exist).
- **Pages are a provider of the page module** (`PageSearchDocuments`, source `page`, registered with
  `search()->addProvider()` like a third party's provider). The text is the title, the summary, and the blocks'
  Markdown made plain (`SearchText`: link and image text kept, addresses and marks dropped), with the media library's
  alt text, captions and transcripts filled in. The breadcrumb is the titles of the published pages above. Pages left
  out of search engines (`noindex`) are still found by the site's own search; editors leave a translation out with
  the new `page_translations.exclude_from_site_search` ("Leave this page out of the site’s own search", shown in the
  editor's *Search & AI* fieldset while search is on). A page contract, `SearchablePages`, tells the search module
  which pages a change touches (the page and those below it; the pages that show a media file).
- **Shared code hands modules what other modules registered:** `Infrastructure\Search\SearchExtensions` (providers by
  source, and the back-end a module put in core's place) and `Tenant\EnabledModules` (whether a module is on for the
  site), both built per site container from the site's own module registry.
- **Keeping it up to date:** `SearchIndexer` listens to page events (published, unpublished, archived, trashed,
  saved, moved, purged; with the pages below), media changes and deletions (the pages that show the file), and
  removed languages; events are dispatched after the transaction. Changes that touch every document (the site's
  languages or default language, who can read the site, search turned off or on) mark the index out of date in the
  site setting `search.index_state`; `schedule:run` then builds it again (`SearchReindexJob`), as it does for a site
  whose index was never built. A failure while indexing is logged and marks the index out of date; it never stops the
  change itself.
- **Commands:** `site:search:reindex <site> [--locale=]`, `site:search:status <site>` and
  `site:search <site> <text> [--locale=] [--members]`. They refuse a site with search off.
- **No stored queries:** each visitor's search dispatches `SearchPerformed`; `site:search` doesn't.

## Consequences

- Search works the same on SQLite, MySQL, MariaDB and PostgreSQL, with no extra service; an architecture test keeps
  SQL strings out of `src/Search/`.
- Indexing a page writes one row per distinct term and part; a full reindex of a large site takes a while and
  belongs in the scheduler or the CLI.
- `search_word` only grows; words whose terms are gone are harmless and go with the next full reindex.
- A module that adds documents must tell the index about changes itself until the contract gets an event for it; a
  full reindex always picks its documents up.
