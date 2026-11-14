# 0007 — URL and locale strategy

- Status: Accepted
- Date: 2026-11-13

## Context

Sites can be published in several languages. Each locale needs stable, canonical URLs that search engines and AI
readers can follow, with `hreflang` alternates between translations. The admin panel also has a language, which is
a different concern: an editor may manage a Portuguese-only site in English.

## Decision

### Public site URLs

- Each site has a `default_locale` and a list of `locales`.
- The default locale has no prefix: `/about`. Every other locale is prefixed with its lowercase tag:
  `/pt/sobre`, `/pt-br/sobre`.
- Slugs are per locale. A locale that isn't in the site's list answers 404.
- When a page isn't translated into the requested locale, fall back in this order: the exact locale, the base
  language (`pt-BR` → `pt`), another region of the same language, then the default locale. Answer with a 302 to
  the fallback URL. Fallback URLs are never canonical and never in the sitemap.
- Each real translation has a canonical URL and `hreflang` alternates to the others, plus `x-default` pointing at
  the default locale.
- Negotiate with `Accept-Language` only on `/`. A signed-in user's preferred locale wins over the header there.
  Every other URL says its locale in the path, so it is never negotiated.

### Admin panel

- The admin panel's initial and default locale is `en-GB`. It is the source language for admin strings and the
  last fallback.
- A user's admin language comes from their preference, then the closest available admin translation, then `en-GB`.
- Site locales are independent of admin locales: a site can use any available locale whether or not the admin
  panel is translated into it.

## Consequences

- Adding a second locale to a site doesn't change any existing URL.
- Changing a site's default locale changes URLs and needs redirects; the CLI and admin must warn about this.
- `en-GB` strings must always be complete; other admin translations can be partial.
- Locale-aware routing, sitemaps and `llms.txt` sections all use the same rules, tested with tables of cases.
