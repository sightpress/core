# Page layouts

What it does: editors choose a layout for each page, from the layouts the site's theme provides. The default theme has
`default` (standard page), `home`, `landing`, `sidebar` and `full-width`.

Who uses it: editors and contributors (page editor), site admins (theme picker).

- Page editor and new page: **Layout** lists the layouts of the site's theme, with what each is for. A page whose
  layout the theme doesn't have says so ("not in this theme: the standard page is used").
- `landing`: a wide introduction band with the title, summary and a call to action, then content across the page.
- `sidebar`: the content at a reading width with a side column, **In this section**. It is an `<aside>` named by its
  heading, with a link back to the page above and links to the published pages under it. Without such pages there is
  no side column. On narrow screens and at 200% zoom it comes after the content.
- `full-width`: content across the whole page width, for wide tables, galleries and columns.
- Changing theme: when pages use a layout the new theme doesn't have, the theme picker first lists them in a table
  ("Use Harbour?"). They are then shown in the standard layout, and they keep their own layout, which comes back when
  the site uses a theme that has it again.
- The theme's manifest decides which layouts it has: a layout file of the default theme doesn't count for another
  theme.

Accessibility: each layout has one `<h1>`, the skip link, landmarks, and 0 axe violations in light, dark and forced
colours at 320 px. Keyboard order: main content first, then the side column (`tests/A11y/appearance.spec.ts`).

Code: `src/Page/Http/SectionAside.php`, `resources/views/page/aside.html.twig`, `src/Page/PageLayoutQuery.php`
(`Contract\PageLayouts`), `src/Theme/Contract/ThemeLayouts.php` (`SiteTheme`), `resources/views/theme/switch.html.twig`.
