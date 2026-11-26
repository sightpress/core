# Block looks: styles, alignment, backgrounds, width, spacing and form options (BL-1)

## What it does

Each block can now have its own look, chosen in the page editor under **Look of block n**. A choice only changes
that one block: copying or moving the block keeps it, and changing one block never changes another.

| Choice | Values | Offered on |
| --- | --- | --- |
| **Style** | Named looks of the block type, for example *Rounded corners* for a picture or *Card* for a form. Modules can add more. | Image, Contact form, and module blocks that have styles |
| **Text alignment** | Start (default), Centre, End. Start and End follow the language, so they flip in right-to-left languages. | Heading, Text, Call to action |
| **Background** | None (default), Surface, Muted, Accent. The colours come from the theme. | Text, Columns, Contact form |
| **Width** | Text width (default), Wide, Full width. | Text, Image, Columns, Gallery, Video |
| **Space above and below** | Medium (default), None, Small, Large. | Every core block |
| **Form layout** | Labels above the fields (default), Labels beside the fields. | Contact form |
| **Form size** | Medium (default), Small, Large. | Contact form |
| **Button position** | Start (default), End, Full width. | Contact form |

- **Contrast is always enough.** Editors can only pick backgrounds from the theme, and SightPress checks every theme
  when it loads: text and links on Surface and Muted, and text on Accent, need 4.5:1 in light mode, dark mode and
  with more contrast. A theme that fails can't be used.
- **No inline styles.** Choices become classes on the block, and the theme styles them. Core supplies a default look
  for every choice, so they work with any theme.
- **Forms stay readable.** Labels are always visible. *Labels beside the fields* goes back to labels above them when
  there isn't room (a narrow column, a phone, or zoomed in), and the reading and tab order never change. Fields and
  buttons never get smaller than 24 × 24 pixels.
- **Centred text** gets a hint: it is harder to read over more than a few lines. There is no justified text.
- **Nothing changes for existing pages.** Blocks saved before this have the defaults and look the same.
- The Markdown version, structured data and site search ignore looks.

## For developers

- Block JSON gains an optional `styles` object next to `data`, holding only non-default choices:
  `{"type": "heading", "version": 1, "data": {…}, "styles": {"align": "center"}}`. See ADR-0064.
- A block type offers looks by implementing `SightPress\Module\Block\StyledBlockType::styles()`, returning
  `BlockStyles` (variants, the first is the default; the `BlockOption`s it offers; `shortForm` for the `inline` form
  layout). `render()` doesn't change: core wraps the HTML in `<div class="block-styled …">`.
- Classes: `block--variant-<name>`, `has-text-align-<value>`, `block--background-<value>`, `block--width-<value>`,
  `block--spacing-<value>`, `form--layout-<value>`, `form--size-<value>`, `form--button-<value>`. Core's defaults are
  in `resources/assets/css/blocks.css`, appended to `tokens.css` inside `:where()`, so a theme overrides them simply
  by styling the classes.
- Themes: colours `muted`, `accent` and `on-accent` are optional; without them Muted falls back to `surface`, and
  Accent to `primary` / `on-primary`. The validator checks whichever colours are used.
- Modules add a variant to another type with `$registrar->blockTypes()->addVariant('image', new BlockVariant('ticket',
  'Ticket'))` and ship its CSS. On a site where the module is off, blocks with it fall back to the default.
- Modules add icons with `addIcon(new BlockIcon(name, label, svgFile, hosts, brandColour))`. Core lists them in
  `Content\BlockIconCatalogue` (`get()`, `forUrl()`, `svg()`); the SVG is sanitised, decorative and at most 64 KB.
