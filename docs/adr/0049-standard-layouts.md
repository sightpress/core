# 0049 — Themes: the standard layouts, the side column, and switching to a theme without a layout

- Status: Proposed
- Date: 2026-11-20

## Context

theme-interface 0.2 defines three standard layouts beyond the required ones (`landing`, `sidebar`, `full-width`), with
fixed ids so that a page keeps its layout when the site changes theme. T-3 adds them to the default theme and lets
editors use them. Two questions were open: what fills the side column, and what happens to a page whose layout the
new theme lacks.

## Decision

- **The theme's manifest decides which layouts a site has** (`ThemeLayouts`, implemented by `SiteTheme`). A layout file
  of the default theme, which every site falls back on for templates, doesn't count for another theme: a page whose
  layout the site's theme doesn't list is shown with `default`. The page editor offers only the theme's layouts, and
  says so for a page whose layout isn't there.
- **The page keeps its layout.** Nothing is rewritten when the theme changes. The layout comes back with a theme that
  has it.
- **The theme picker asks first.** When pages use a layout the chosen theme lacks, it lists them in a table (title and
  layout, through the Page contract `PageLayouts`) and applies the theme only after "Use …" is pressed again.
- **The side column is core's:** `SectionAside` fills the `sidebar` layout's `aside` block with "In this section". That
  is a heading (`page-aside-title`, which the theme's `<aside>` is named by) and links to the page above and the
  published pages under it that the visitor may read. With nothing to show there is no side column. Editors don't
  write it, so it is never empty or out of date.

## Consequences

- A theme author only provides the template. The contents of the side column, and the accessibility of its heading,
  are the same in every theme.
- Pages in a layout another theme lacks look plainer after a switch, and the admin knew before switching.
