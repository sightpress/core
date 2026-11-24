# 0064 — Block variants, shared options and icons in the module contract

- Status: Proposed
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
