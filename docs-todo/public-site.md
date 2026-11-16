# The public site: home page and page addresses (C-6.2)

## What it does

Visitors read a site's published pages at their addresses. Everyone uses it; nobody needs a permission to read a
public page.

- **The home page is at `/`.** It is the page chosen in the site setting `page.home` (starter content sets it); a
  site that never chose one uses the first page at the top of the page tree. Its version in another language is at
  the language prefix alone, for example `/pt-pt`. The home page's own address (`/home`) moves permanently to `/`.
- **Every other page is at its address**: `/about/team` in the site's main language, `/pt-pt/sobre/equipa` in the
  others (ADR-0007).
- **Only pages the visitor may read are shown**: published, and public (or members only, once the visitor has
  signed in). Drafts, pages in review, missing pages and members-only pages for visitors who haven't signed in all
  answer with the same accessible "Page not found" page, so nothing is given away.
- **One address per page.** `/about/` moves permanently (301) to `/about`.
- **Layout.** A page is shown in the theme layout it uses (`home` or `default`); a layout the theme doesn't have
  falls back to `default`. The `<html lang>` is the page's language.

## Caching

- A page with nothing personal on it (the visitor hasn't signed in, and the page doesn't hold a form) is sent with
  `Cache-Control: public, max-age=300`, so browsers and shared caches can keep it for five minutes, and without a
  session cookie.
- Pages for signed-in people and pages with a form (the contact form) are `private, no-cache`.
- Every page has an `ETag`; a browser that sends it back in `If-None-Match` gets `304 Not Modified`.
- Every page says its language in `Content-Language`.

## For developers

- Module `page`: routes `page.home` (`/`) and `page.show` (`/{path:.+}`), both `GET` and `HEAD`, handled by
  `SightPress\Core\Page\Http\PublicPageHandler`; template `@page/public.html.twig` extends the page's layout.
- `PageAddresses` turns addresses into a locale and a path and back, and knows the home page.
- The router adds routes whose path starts with a placeholder after every other route, so module routes such as
  `/media/…` and `/admin/…` always win over the page route, whatever order modules register in.
- Tests: `tests/Unit/Page/Http/PublicPageHandlerTest.php`, `tests/Unit/Page/PageAddressesTest.php`,
  `tests/Integration/Page/PublicSiteTest.php`, `tests/A11y/blocks.spec.ts`.
