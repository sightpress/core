# Videos and maps from other sites, lists of pages, sign language videos (BL-2)

## What it does

Three new kinds of block in the page editor:

| Block | What it shows | Accessibility and privacy |
| --- | --- | --- |
| **Video or map from another site** | A YouTube or Vimeo video, or an OpenStreetMap map, from its link, with a title. | Nothing from the other site loads until the reader presses *Show the video* (or *Show the map*). Until then the page explains who the provider is and links to their privacy policy and to the original. Without JavaScript the link is there. The title names the frame for screen readers, and focus moves to it once it is shown. |
| **List of pages** | The pages under a page (or the site's top-level pages), or the pages changed most recently, up to 12, as a list or as cards, with their summaries if you like. | A real list, one link per page; on cards the whole card follows that link. It only ever lists pages everyone may read, so members-only pages never appear, not even in the Markdown version, `llms.txt` or search results. It keeps itself up to date. |
| **Sign language video** | A video in a sign language (BSL, ISL, ASL, LSF, DGS, LSE, LGP, LIS, NGT, USL, Israeli Sign Language or International Sign). | The language is named under the video and tagged for search engines. Captions are still required, for people who don't sign or use another sign language. |

- Only YouTube, Vimeo and OpenStreetMap links are accepted. Other links are refused when you save, so a page never
  loads something from a site nobody checked. YouTube videos use YouTube's privacy-enhanced address, and Vimeo is
  asked not to track.
- On a members-only site, the list of pages stays empty for now, because none of its pages are open to everyone.
- The home page and the page the list is on are never in the list.

## For developers

- Block types `embed` and `sign_language_video` (block module), and `page_list` (page module, a service of each site
  because it reads pages). See ADR-0074.
- `Block\Contract\EmbedProviders`: the allow-list, `resolve($url)` (provider and frame address) and
  `frameOrigins()`, which the CSP's `frame-src` lists. The button is wired by `/assets/js/embed.js`, loaded as a
  module once per page.
- `page_list` data: `source` (`pages_under_a_page` | `latest_changed_pages`), `parent` (a page address),
  `count` (1–12, default 6), `show_summaries`. Variants `list` and `cards`. JSON-LD: `ItemList`.
- `sign_language_video` data: like `video`, plus `sign_language` (a choice that maps to an ISO 639-3 code, see
  `SignLanguageVideoBlock::LANGUAGES`), and `captions` is required.
- theme-interface 0.3.0 shows all three on the preview page. It removes `Screenshot` and makes `previewDescription`
  required, so themes declare `^0.3` (theme-default 0.3.1 and theme-template 0.1.2 accept both).
