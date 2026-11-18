# Locale routing and fallback (I-2)

How visitors and search engines reach each language of a page (ADR-0007).

## Addresses

- Pages in the main language have no language part: `/about`. The others start with the lower-case locale:
  `/pt-pt/sobre`, `/he/odot`. `/PT-PT/sobre` and `/en-gb/about` (the main language written out) are moved
  permanently (301) to the right address. A language part the site doesn't have (`/fr/about`) is a 404.
- `<html lang dir>`, `Content-Language`, `og:locale` and `inLanguage` follow the page's language; Hebrew, Arabic and
  other right-to-left languages get `dir="rtl"`.

## Fallback

When a page isn't (yet) published in the language asked for, visitors get a **302** to the closest language the page
is published in: the exact locale, then the language without region (`pt-BR` → `pt`), then the same language in
another region (`pt-PT`), then the main language. A script counts as part of the language (`zh-Hant` never falls
back to Simplified Chinese). The page is found at its address in the asked language or, if it has none there, at the
same address in another language (which then leads to the asked language's own address when it has one). The 302
says the language it leads to (`Content-Language`) and lists the page's real translations in `Link` headers with
`hreflang` and `x-default`. Fallback addresses are never canonical and never in a sitemap or `llms.txt`.

## The home page and `Accept-Language`

Only `/` is negotiated: a signed-in person's own language first, otherwise the browser's `Accept-Language`, with the
same fallback rules. If the home page is published in that language, `/` is a 302 to it (`/pt-pt`); otherwise the
main-language home page is shown (`Vary: Accept, Accept-Language`). Someone who followed a link on the site itself
(a language switcher pointing at `/`, say) is never sent away: the `Referer` of the same host skips negotiation.
Search engines don't send `Accept-Language`, so they always get the main language at `/`.

## Search engines and AI readers

- `/sitemap.xml` is a sitemap index when the site has more than one language, pointing at `/sitemap-<locale>.xml`
  (one per language). Each entry lists every real translation of the page as an alternate, itself included, and
  `x-default` (the main language). A language the site doesn't have is a 404.
- The `<head>` has the same `hreflang` set as the sitemap (only translations search engines may list: published,
  public, not `noindex`, not a copy), `x-default`, the Markdown version of each translation with its `hreflang`, and
  `og:locale:alternate`.
- `llms.txt` has a section per language ("## Pages in European Portuguese (pt-PT)").

## Tests

`tests/Unit/Tenant/LocaleFallbackTest.php` (tables of fallback and `Accept-Language` cases),
`tests/Unit/Page/Http/PublicPageHandlerTest.php`, `tests/Integration/Seo/SeoTest.php` (schema-valid index and
per-language sitemaps), `tests/A11y/translations.spec.ts` (RTL page with axe, Lighthouse SEO 100 on translated pages,
reciprocal sitemap and head sets) on a translated copy of the starter site (port 8084).
