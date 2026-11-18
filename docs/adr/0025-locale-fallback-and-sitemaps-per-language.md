# 0025 — Locale fallback redirects, home page negotiation and a sitemap per language

- Status: Proposed
- Date: 2026-11-18

## Context

ADR-0007 decided the URL and fallback rules; I-2 implements them. Three details were open: how `/` can be negotiated
without making the main-language home page unreachable for visitors whose browser prefers another language, how the
sitemap is split by language, and how the `<head>` knows which translations search engines may list, so that the
`hreflang` sets in the head and in the sitemap are the same.

## Decision

- `Tenant\LocaleFallback` holds the rules: exact → language (with its script) → same language and script in another
  region → default; and the best site language for an `Accept-Language` header.
- A page not published in the asked language is a 302 to the closest one (`Content-Language`, `Link` headers with
  every real translation and `x-default`). A page is found by its address in the asked language or, when none, by the
  same address in another of the site's languages.
- Only `/` is negotiated: the signed-in person's language, else `Accept-Language`. A request whose `Referer` is the
  site itself is not negotiated, so links to `/` on the site (a language switcher) always reach the main-language home
  page. The redirect is `private` and varies on `Accept-Language`; the home page itself varies on it too.
- Locale parts of addresses are lower case; `/PT-PT/…` and the main language written out (`/en-gb/…`) are 301s.
- With more than one language, `/sitemap.xml` is a sitemap index of `/sitemap-<locale>.xml`.
- `Page\Contract\PageLink` gains `$indexable` (default false): published, public, not `noindex`, not a copy. The SEO
  module's `hreflang` alternates use only those, like the sitemap. An additive contract change.

## Consequences

- Fallback URLs are never canonical, never in a sitemap or `llms.txt`.
- A visitor who types the address of the site with a browser in Portuguese lands on the Portuguese home page; a link
  from inside the site to `/` keeps them on the English one.
- Code that builds `PageLink` keeps working; it must pass `indexable` for the link to count as an alternate.
