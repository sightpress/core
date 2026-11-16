# Search engines: titles, descriptions, sitemap and robots (C-6.7)

## What it does

Every public page is ready for search engines without anyone having to think about it.

- **Title**: the page's "title for search engines" if it has one, else its title followed by the site's name
  ("About us - Riverside Library"); a title that already names the site is used as it is.
- **Description**: the page's description, else its summary, else its first words. A description is at most
  160 characters: longer text is cut at a whole word and ends with "…". Social networks get the same one.
- **One address per page** (the canonical address), in `<link rel="canonical">` and a `Link` header:
  - `/about/` moves permanently (301) to `/about`;
  - the site's other domains (`www.example.org`) move permanently to its first one (`example.org`);
  - a page that copies another one can name the original ("original address"); it then isn't in the sitemap.
- **Other languages**: on sites with more than one language, `hreflang` alternates (and `x-default`).
- **Robots rules** (also sent as `X-Robots-Tag`):

  | Install        | Page                  | Search engines are asked   |
  | -------------- | --------------------- | -------------------------- |
  | not production | any                   | not to index, not to follow (`noindex, nofollow`) |
  | production     | members only          | `noindex, nofollow`        |
  | production     | public, "noindex" set | `noindex`                  |
  | production     | public                | nothing: index it          |

- **Structured data** (schema.org JSON-LD): a `WebPage` (part of the site's `WebSite`, with when it changed), its
  `BreadcrumbList`, and what the blocks add (pictures, videos, audio).
- **"Last updated"** under the content of pages in the default layout, as a `<time>` in the reader's time zone.
- **`/sitemap.xml`**: every page search engines may index (published, public, not "noindex", not a copy), in every
  language, with `<lastmod>` and `hreflang` alternates; valid against the sitemaps.org schema.
- **`/robots.txt`**: keeps crawlers out of `/admin`, `/account`, sign-in, password and invitation pages and points
  them to the sitemap; outside production it keeps them out of everything.
- Both files are cached and made again whenever a page is created, saved, moved, published or unpublished.

## CLI

```text
bin/sightpress site:seo:check demo
```

It lists titles over 60 characters, missing or over-long descriptions, pages left out of search (and why) and
whether the install is in production; two pages with the same title are a problem (exit code 1).

## For editors

The page editor (C-7.2) gets a "Search & AI" fieldset for the title for search engines, the description,
"noindex" and the original address. Fields on `page_translations`: `seo_title`, `noindex`, `canonical_url`.

## For developers

- Module `seo` (required): `SeoHead` (listens to `PublicPageRendering`, priority 10, before branding), `SeoMeta`,
  `RobotsPolicy`, `SeoFiles` (public), `SeoCachePurger`, `site:seo:check`.
- New page contract `PageIndex` / `IndexedPage` (every published page, one query). `PublicPageRendering` now
  carries the search fields, the blocks and their HTML, and lets listeners set the description, canonical
  address and robots rules.
- Lighthouse's SEO category must score 100 on every starter page: `tests/A11y/starter.spec.ts` (runs in the
  accessibility job).
- Tests: `tests/Unit/Seo/`, `tests/Integration/Seo/SeoTest.php`.
