# 0064 — Block variants, shared options and icons in the module contract

- Status: Accepted (core part: BL-1, 2026-11-25)
- Date: 2026-11-24

## Context

`blocks.md` §2–§4 plan per-block variants, shared section options (alignment, background, width, spacing) and form
options, and module registration of icons and variants before the API freezes. Stage 4 gap 2 puts the contract part
in `module-interface` 0.5, so the module template and tutorial teach the final shape; core's rendering and editor
work is BL-1.

## Decision

- Add `Block\StyledBlockType extends BlockType` with `styles(): BlockStyles`. It is optional, so `BlockType` doesn't
  change and existing block types keep working.
- `BlockStyles`: the type's variants (`BlockVariant`: name, label; the first is the default), the shared
  `BlockOption`s it offers, and `shortForm` (allows the `inline` form layout; needs `form_layout`).
- `BlockOption` lists the options and their values, default first: `align` (start, center, end; logical, no
  justify), `background` (transparent, surface, muted, accent), `width` (content, wide, full), `spacing`,
  `form_layout` (stacked, horizontal, inline), `form_size`, `button_align`.
- `BlockTypeRegistrar::addVariant(blockType, BlockVariant)` lets a module add a variant to another type, and
  `addIcon(BlockIcon)` adds an icon (name, label, SVG file, hosts, optional brand colour `#rrggbb`).
- Choices are stored in each block's own data and drawn by core as classes on the block's wrapper, never inline
  styles; `render()` doesn't change.

## Consequences

- BL-1 implements storage, the editor fields, the wrapper classes, the `BlockUpgrader` step and the contrast checks.
- A variant of a module that is off falls back to the type's default variant.

## Core implementation (BL-1)

- **Storage.** A block is `{type, version, data, styles?}`. `styles` sits next to `data`, so `schema()` and
  `render()` don't change, and holds only the choices that differ from the default (`{"variant": "card",
  "align": "center"}`). A block without it looks as it always did, so no data version bump and no migration are
  needed. `PageContent` accepts the extra key (text names and values only).
- **Checks.** `DefaultBlockValidator` checks `styles` against what the type offers *on this site* (its own variants,
  the variants modules that are on add, the options it lists) and refuses anything else with a field error at
  `/n/styles/<choice>`. The editor (`BlockData`) and the renderer keep only possible choices, so a block whose
  variant came from a module that is now off falls back to the default (`BlockUpgrader::styles()`, the upgrader step).
- **Variants from modules.** `BlockTypeCatalogue::stylesOf()` merges the type's variants with those modules add
  through `addVariant()`. A variant for a type that isn't on the site, offers no variants, or already has one by that
  name is left out instead of failing the site.
- **Drawing.** `DefaultBlockRenderer` wraps a block that has choices in `<div class="block-styled …">` with
  `block--variant-<name>`, `has-text-align-<start|center|end>`, `block--background-<name>`, `block--width-<name>`,
  `block--spacing-<name>`, `form--layout-<name>`, `form--size-<name>` and `form--button-<name>`. Blocks with only
  defaults get no wrapper, so existing pages keep their markup. Markdown, JSON-LD and search text don't change.
- **Default CSS.** Core appends `resources/assets/css/blocks.css` to every theme's generated `tokens.css`. Every rule
  is inside `:where()`, so the theme's stylesheet (loaded after it) always wins. Backgrounds use theme colours with
  fallbacks: `surface` (→ `background`), `muted` (→ `surface` → `background`), `accent` + `on-accent`
  (→ `primary` + `on-primary`). The horizontal form layout uses a container query, so it stacks in narrow columns
  and at 400 % zoom; DOM order never changes.
- **Contrast.** `ThemeValidator` checks the same fallbacks at theme load in every colour mode: `text` and `link` on
  the surface and muted backgrounds, `on-accent` on accent (4.5:1). Pairs the theme already lists aren't counted twice.
- **Editor.** Each block of a type with looks gets a *Look of block n* fieldset with one select per choice (variant
  first, the default preselected, hints for alignment, background and form layout). Labels are translated.
- **Core types.** heading and call to action: align, spacing; rich text: align, background, width, spacing; image:
  plain / rounded / bordered, width, spacing; columns: background, width, spacing; gallery and video: width, spacing;
  audio and file download: spacing; contact form: plain / card, background, spacing, form layout (stacked,
  horizontal), form size, button position.
- **Icons.** `Content\BlockIconCatalogue` lists the icons modules that are on register: by name, or by a link's host
  (subdomains match, the longest host wins). The SVG is read once, cleaned with the upload sanitiser, made
  `aria-hidden="true"` and `focusable="false"`, given `fill="currentColor"` unless it sets a fill, and stripped of
  sizes, titles and labels; a missing, oversized (> 64 KB) or empty file is left out. Brand colours and their 3:1
  check come with `social_links`.

Left for later: the revision comparison doesn't show changes of look yet; theme manifests can't add variants yet
(blocks.md §3); `code`, `hero` and the other new types bring their own variants when they land.
