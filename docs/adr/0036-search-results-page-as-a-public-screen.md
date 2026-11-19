# 0036 — The search results page: a public screen with the site's menus, routes before the page catch-all

- Status: Proposed
- Date: 2026-11-19

## Context

SR-2 adds the site search form to every public page and a results page at `/search` (and `/pt-pt/search` for the
site's other languages). Three things in core didn't fit it yet:

- The results page should look like the rest of the public site (menus, logo, language switcher), but it isn't a
  page: it must have no canonical address, no social or schema.org tags of a page and no breadcrumb of pages above
  it. The menus and the logo come from listeners of `PublicPageRendering`, which only page rendering dispatches.
- Routes that start with a placeholder (`/{locale}/search`) were sorted after every module's routes together with
  the public pages' catch-all `/{path:.+}`, so the catch-all won.
- The plan asks for a `SearchAction` in the site's `WebSite` JSON-LD while search is on; the branding module builds
  that node.

`theme-interface` 0.1 already describes a `search` layout and a `search-form` partial (with `role="search"` and the
count in a live region). Gap 6 of the stage plan settled that M2 builds the theme work in core's default theme, and
that `theme-interface` 0.2 follows the default theme before M2's release.

## Decision

- **`Page\Contract\PublicScreenRendering`**: an event for public screens that aren't pages (request, locale,
  address, base URL, title; template variables and `<head>` HTML like `PublicPageRendering`). The menu module gives
  them the menus and a breadcrumb "Home › <title>"; the branding module gives them the icons, the manifest,
  `theme-color` and the logo, but no Open Graph, Twitter or schema.org tags.
- **Only a route whose whole path is one placeholder catches every address** (`RouteInstaller`): `/{path:.+}` still
  comes last, `/{locale}/search` now comes before it. The page module already reserves `search` as a top-level slug
  in every language, so no page can take those addresses.
- **The results page** (`SearchHandler`, in the search module): `GET /search?q=…&page=…`, and
  `/<locale>/search` for the site's other languages. `/<main locale>/search` is moved to `/search` (301), and
  any other language answers 404. The page is rendered in the theme's `layouts/search.html.twig`
  through the module view `@search/results.html.twig`. Its `<title>` and `<h1>` say what was searched for and how many
  results there are (and the page of results in the `<title>`), so a screen reader announces both when the page
  loads; there is no live region on load. It answers `X-Robots-Tag: noindex, follow` with the same robots meta tag,
  `Cache-Control: private`, and has no canonical link. Pages after the last one show the last one; at most 100
  pages are offered.
- **Extracts are pieces of plain text** (`SearchExtract`): about 240 characters around the first matched word, from
  the summary when it has a match, else the body. The template prints each piece escaped, inside `<mark>` when it
  matched (bold and underlined, so it also shows in forced colours). Scripts written without spaces match without word
  boundaries. Nothing from a document or a query is ever printed as HTML.
- **The header form** comes from the search module: a `PublicPageRendering` listener sets `search_action` (the
  results page in the page's language) and the default theme's master template includes
  `partials/search-form.html.twig` when it is set. The form is a `<search>` landmark named by its visible label
  ("Search this site"), with an input named `q` and a visible **Search** button. The results page has a second form
  ("Search for") with its own ids. With search off, nothing sets `search_action`, so the form isn't there.
- **Rate limit:** a `search` policy, 30 searches a minute per address (sliding window). Over it, the results page
  answers 429 with `Retry-After` and says in words how many minutes to wait. An empty form isn't a search.
- **`SearchAction`:** while the search module is on, the branding module adds `potentialAction` to the `WebSite`
  node (`/search?q={search_term_string}`). Branding reads the address and parameter from the new
  `Search\Contract\SearchPage` and asks `Tenant\EnabledModules` whether search is on.

## Consequences

- Other modules with public screens (an events calendar, say) can use `PublicScreenRendering` and get the same
  chrome without pretending to be a page.
- A module route that starts with a placeholder no longer loses to the public pages, which is what a module author
  would expect.
- The default theme's search templates go beyond `theme-interface` 0.1 (a `<search>` landmark, count in the title
  and heading, marked extracts, `state`, `trail`, `extract`). `theme-interface` 0.2 should describe them before M2's
  release, as gap 6 planned.
