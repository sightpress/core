# 0032 — Menu entries per language and for members, links that follow moved pages

- Status: Proposed
- Date: 2026-11-18

## Context

MN-1 polishes menus and breadcrumbs for M2's languages and members-only content (plan §2; stage-2 gap 2). Menus
already showed a page entry with the page's title in the visitor's language and left out pages not published in it.
Missing: a menu entry's own text in other languages, entries for signed-in members only, members pages in the menus
of signed-in people, links to addresses whose page moved (P-1 redirects), and menu entries for pages deleted for good
(left as "Missing page" since P-1).

## Decision

- **Storage**: two columns on `menu_items` (portable migration): `labels` (JSON, locale => text, null when none) and
  `members_only` (boolean, default false). The existing `label` stays the text in the site's main language, so menus
  made before keep working.
- **Text in a language** (`MenuItem::labelIn()`): the entry's own text in that language; else, in the main language
  (or for a link), its main text; else a page entry shows the page's title in that language.
- **Who sees an entry** (`PublicMenus`, per request): a page entry needs the page published in the page's language and
  open to the visitor — open to everyone, or published and the visitor signed in (members pages, members-only
  sites); an entry marked members-only needs a signed-in visitor. Hidden entries take the entries under them along.
  Breadcrumbs follow the same rule.
- **`PageLink::$published`** (appended, default false) says a page is published in that locale whoever may read it,
  so menus can show members pages to members while `public` keeps meaning "open to everyone".
- **Moved pages**: a new contract `Page\Contract\MovedAddresses` (`RedirectedAddresses`, one hop through the
  redirects table) gives a link entry's address of this site its current address, keeping the query and fragment;
  a link to an address whose page was deleted is left out. Page entries already point at the page itself.
- **Deleted pages**: the menu module listens to `PagePurged` and removes those pages' entries (audited as removals).
- **Admin**: each entry in the menu editor says in words whether it is for members only and in which of the site's
  languages its page isn't published yet, and has a "Change" link to `/admin/menus/{id}` (texts per language,
  members-only checkbox; `menu.manage`). Changes dispatch `MenuChanged` with `changed`.

## Consequences

- Menus differ between visitors and signed-in people; signed-in pages are already never shared-cached.
- `MovedAddresses` costs up to two queries per local link entry on each page view; menus have few links, and the
  public page cache absorbs it for visitors.
