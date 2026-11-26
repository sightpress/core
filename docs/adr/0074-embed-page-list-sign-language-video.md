# 0074 — The embed, page list and sign language video blocks

- Status: Accepted
- Date: 2026-11-26

## Context

`blocks.md` §1 plans three blocks for M4: `embed` (a video or map from another site), `page_list` (pages kept up to
date as a list or cards) and `sign_language_video`. Stage 4 gap 2 puts them in BL-2, on top of the block looks of
BL-1 (ADR-0064). Each new core block type needs a sample in `theme-interface`'s preview page, because core's test
fails on a block type without one.

## Decision

- **`embed`** (block module). Only YouTube (on its privacy-enhanced host), Vimeo (with "do not track") and
  OpenStreetMap, from `Block\Contract\EmbedProviders`. The schema only accepts their links, and the block turns a
  link into the address of the provider's player or map. A title is required. The page holds no frame and nothing
  from the provider: there's a notice that names the provider and says that showing it lets them see the visit, a
  link to their privacy policy, a link to the original (which works without JavaScript) and a button that
  `/assets/js/embed.js` shows. Pressing the button swaps the notice for the frame and moves focus to it. Nothing is
  remembered, so there's no cookie or storage and no consent record. The site's Content-Security-Policy gains
  `frame-src 'self'` plus the three providers' frame origins. Markdown is the link.
- **`page_list`** (page module, because it reads pages): the pages under a page (by its address; the top-level
  pages when empty) or the pages changed most recently, at most 12. It leaves out the home page and the page it is
  on (in the HTML; the Markdown version and the search index are made without a request, so there it can list
  itself). It only lists pages everyone may read (published and public, on a public site), so a members-only page
  never reaches guests, search engines or AI readers in the HTML, the Markdown, `llms.txt` or the JSON-LD
  (`ItemList`).
  Members don't see members-only pages in it either, for now. Variants are `list` and `cards`: a `<ul>` with one link
  per page, and on cards the whole card follows that link. It is a service of each site (a block type that isn't a
  service is built by the tenant container, which has no database).
- **`sign_language_video`** (block module): a video block with a required sign language chosen from a list (ISO
  639-3: BSL `bfi`, ISL `isg`, ASL `ase`, LSF `fsl`, DGS `gsg`, LSE `ssp`, LGP `psr`, LIS `ise`, NGT `dse`, USL `ukl`,
  Israeli `isr`, International Sign `ils`). Captions are still required. The language is tagged
  (`data-sign-language`, `inLanguage` in the `VideoObject`) and named under the video. Language names are in en-GB,
  like the rest of the text core's blocks draw.
- **theme-interface 0.3.0** carries the samples (a *Find your way* section) and lets sample blocks have `styles`. As
  announced in 0.2, it also removes `Screenshot` and makes `previewDescription` required. theme-default 0.3.1 and
  theme-template 0.1.2 accept `^0.2 || ^0.3` first, then core requires `^0.3`. Theme checks have no warnings any
  more, because both warnings were about what 0.3 removed.
- The demo site's *Every kind of block* page gains all three blocks, so the crawl checks them.

## Consequences

- A new embed provider means a new entry in `EmbedProviders`, which also widens the CSP. That's deliberate: every
  provider is reviewed for privacy and for what its player needs.
- `page_list` reads the database while it renders, unlike the other block types (the module contract asks block
  types not to). It's a core block that uses its own module's query. Lists on cached pages are as fresh as the cache.
- Left for later: listing members-only pages to signed-in members, translated sign language names, embeds that
  remember a reader's choice.
