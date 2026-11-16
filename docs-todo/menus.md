# Menus and breadcrumbs (C-6.3)

## What it does

Every site has two menus: the **main menu** at the top of every page and the **footer menu** at the bottom.
Editors and admins (permission `menu.manage`) change them in the admin under **Menus** (`/admin/menus`).

- **Entries** are pages of the site or links. A page entry shows the page's title in the visitor's language;
  a link has its own text and an address that starts with `https://`, `http://`, `mailto:`, `tel:` or `/`.
- **Three levels deep.** Each entry has buttons: **Move up**, **Move down**, **Move in** (under the entry before
  it), **Move out** (after the entry it was under) and **Remove**. Buttons that can't do anything for that entry are
  not shown. Removing an entry keeps the entries under it: they take its place.
- **Pages that aren't published** can be added; visitors only see them once they are published and public. The
  editor says "not shown until the page is published".
- On the public site, a page that has pages under it in the main menu gets a button next to its link ("Show the
  pages under About") that opens and closes them. Escape closes them again. Without JavaScript every level is shown.
- **Breadcrumbs** appear on every page except the home page: Home, then the pages above, then the current page
  (marked as the current page for screen readers).

## Accessibility

- Each button's name says which entry it moves: "Move up, About". After a change, the result is announced in the
  live region and focus goes back to the same button, or to the opposite one when that button is gone (the entry
  is now first, last or as deep as it can go). After removing an entry, focus goes to the menu's heading.
- The menus are `<nav>` landmarks named "Main" and "Footer"; breadcrumbs are a `<nav>` named "Breadcrumb" with an
  ordered list. The submenu buttons use `aria-expanded` and `aria-controls`.

## For developers

- Module `menu` (required): table `menu_items`, `MenuEditor` (public) with `add`, `remove`, `moveUp`, `moveDown`,
  `indent`, `outdent`; each dispatches `Menu\Contract\MenuChanged` (for the audit log).
- `PublicMenus` listens to `Page\Contract\PublicPageRendering` (new) and sets `main_menu`, `footer_menu` and
  `breadcrumbs` for the theme. Other modules can add template variables and `<head>` tags the same way.
- New page contract `PageLinks` (`find(ids, locale)`, `all(locale)`), one query each.
- Tests: `tests/Unit/Menu/`, `tests/Integration/Menu/MenuFlowTest.php`, `tests/A11y/menus.spec.ts`,
  `tests/A11y/blocks.spec.ts`.
