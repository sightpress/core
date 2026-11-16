# Pages: the page tree, translations and revisions (C-4.1)

## What it does

Every site has a tree of pages. A page can sit at the top level or under another page, up to 10 levels deep, and
the pages under one parent are kept in the order the editor chose.

- **One page, several languages.** A page is written once per language of the site. Each language has its own
  title, address (slug), summary, description for search engines and content blocks. A page can only be written
  in a language when the page above it is written in that language too, so every address has a parent address.
- **Addresses.** A page's address is its parent's address plus its own slug: `/about/team`. Slugs use lower-case
  letters a to z, digits and single hyphens, at most 60 characters. A slug is made from the title when none is
  given ("Über uns" → `uber-uns`). Two pages under the same parent can't have the same slug in the same language.
  Top-level pages can't use addresses the system needs (`admin`, `login`, `logout`, `account`, `media`,
  `assets`, `password`, `register`, `search`, `api`) or the prefix of one of the site's languages (`pt`, `pt-br`).
- **Changing a slug or moving a page** changes the address of every page below it, in that language. A page can't
  be moved under itself or under one of its own subpages.
- **Revisions.** Every save keeps a copy of the whole page (its place in the tree, its settings and every
  language with its blocks), numbered 1, 2, 3 … per page, with who saved it and when (in UTC).
- **New pages** start as public drafts using the `default` layout.

Clear messages say what to fix, for example: *The address "/about/team" is already used by the page "Team".
Choose another one.*

## For developers

- Module `page` (required), tables `pages`, `page_translations`, `page_revisions`.
- `PageEditor::create()`, `save()` and `move()` check everything first, then write in one transaction and
  dispatch `PageCreated`, `PageSaved` and `PageMoved` (`SightPress\Core\Page\Contract`).
- `PageRepository` finds pages by id or by address (`findByPath('en-GB', 'about/team')`), lists children and
  whole branches (materialized path, one query each, translations fetched in the same query) and revisions.

## Still to write in the docs

- Reference: page fields and limits, address rules, reserved addresses.
- How-to (non-developers): create a page, put it under another page, change its address, translate it.
