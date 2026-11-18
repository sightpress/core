# Menus and breadcrumbs in every language (MN-1)

The main and footer menus, and the breadcrumbs, follow the language of the page and who is reading. ADR-0032.

## Text of an entry in each language

**Menus → Change** (next to each entry) opens the entry's own screen:

- **Text in <language>**, one field per language of the site. For a page entry every field is optional: empty means
  the page's title in that language. For a link, the text in the main language is needed; the other languages fall
  back to it.
- **Only show this entry to members who are signed in** — for links or pages only members should see in the menu.

Saving takes you back to the menus, says "… has been changed." and puts focus on the entry.

## What visitors see

- A page entry appears in a language only when the page is published in that language; the menu editor says so in
  words: "not shown in these languages until the page is published in them: …".
- Pages for members only (and every page of a members-only site) appear in the menus and breadcrumbs only for people
  who are signed in; so do entries marked for members only.
- The entry for the page you are reading is marked as the current page (`aria-current="page"`) in every language.

## Moved and deleted pages

- A page entry always leads to the page, wherever it moves.
- A link entry to an address of the site whose page moved leads to the page's new address (through the redirects
  list). A link to an address whose page was deleted is left out.
- When a page is deleted for good, its menu entries are removed too (the entries under them move up).
