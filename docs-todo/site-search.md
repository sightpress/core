# Site search (SR-1)

Site search finds a site's published pages, in the language being read. It is an optional feature, on by default for
new sites; admins turn it off or on on the **Features** screen or with `site:module:disable|enable <site> search`.
ADR-0011, ADR-0035.

## What is found

- Every published page, in each of the site's languages it is published in: its title, its summary, and the text of
  its blocks, with the alt text and captions of its pictures, the transcripts of its videos and recordings and the
  names of its downloads.
- Pages for members only (and every page of a members-only site) are only found by people who are signed in.
- Drafts, pages waiting for review, archived pages, pages in the trash and translations that are drafts of their own
  are never found.
- Pages that ask search engines not to list them are still found by the site's own search. To leave a page out of
  the site's search, tick **Leave this page out of the site’s own search** in the page editor's *Search & AI* section
  (one choice per language; only shown while search is on).
- Other modules can add their own content (`ModuleRegistrar::search()->addProvider()`), or replace the search engine
  (`->useBackend()`).

## How words are matched

- Capitals and accents don't matter: "cafe" finds "Café", "acessivel" finds "acessível".
- Words are reduced to their stem where the language has a stemmer (English, Portuguese, Spanish, French, German,
  Italian, Dutch, the Nordic languages, Catalan, Romanian, Russian): "libraries" finds "library".
- Very common words ("the", "of", "de", "und" …) are left out.
- Chinese, Japanese and Thai, written without spaces, are split into words.
- The last word also finds longer words it starts: "accessib" finds "accessibility".
- Pages with every word come first. When no page has every word, pages with any of them are shown, and the results
  say so.
- A match in the title counts more than one in the summary, which counts more than one in the text. Newer pages get
  a small boost.
- At most 200 characters and 10 different words are searched for.

## Keeping it up to date

The index follows every change as it is saved: publishing, unpublishing, archiving, the trash, saving, moving (with
the pages below) and deleting pages, and changing a picture's alt text or a recording's transcript. Changes to the
whole site (its languages, its main language, who can read it, turning search on again) rebuild the index on the next
run of `schedule:run` (once a minute). A site whose index was never built is built the same way.

## Command line

- `site:search:reindex <site> [--locale=pt-PT]`: build the index again now (from the published content).
- `site:search:status <site>`: how many documents each language has, when the index last changed, and whether it
  is waiting to be rebuilt.
- `site:search <site> "<words>" [--locale=pt-PT] [--members]`: search like a visitor (or a signed-in member) would.

## Privacy

Core stores no searches. Each search dispatches an event with the words, the language and the number of results,
and nothing about who searched, for modules such as analytics.
