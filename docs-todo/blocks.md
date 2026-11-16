# Blocks: content blocks and the core block types (C-4.2)

## What it does

A page's content is a list of blocks, one list per language. Each block has a type that decides which fields it
has and how it looks. Core comes with five types; modules can add more.

| Block | What it holds | Accessibility built in |
| --- | --- | --- |
| **Heading** | Text and a level from 2 to 6 (the page title is the only level 1). | Levels that skip are flagged before publishing (C-4.5). |
| **Text** | Formatted text: paragraphs, lists, links, bold, italics, quotes, code, subheadings, text colour. | Links without text stop publishing; vague link text ("click here", a bare address) gets a warning. |
| **Image** | A picture from the media library, alt text or "decorative", optional caption. | Missing alt text stops publishing; alt text that looks like a file name, starts with "image of" or is longer than 250 characters gets a warning. |
| **Call to action** | Button text and where it goes (a page on the site, a web address, `mailto:` or `tel:`). | Vague button text gets a warning. |
| **Columns** | Two to four columns, each with its own blocks (up to 50). | Columns stack in reading order on small screens and when zoomed. A columns block can't go inside another one. |

- **Saving checks everything.** Each block is checked against its type when the page is saved. Every problem is
  listed at once, with a message that says how to fix it, and the editor moves to the field (for example *Fill in
  this field.* or *Choose a picture.*). Nothing is saved until every block is fine.
- **Text is cleaned.** Formatted text keeps only safe formatting. Scripts, styles other than text and background
  colour, embedded frames and images are removed (use an Image block for pictures). Layout wrappers pasted from other
  editors are removed but their text is kept.
- **Files are checked.** A block can only use files that are in the site's media library, and of the right kind (a
  picture for an Image block). Only published files are shown on the public site.
- **Every block has a text version.** Blocks are also turned into Markdown for readers that prefer text, such as AI
  search engines, with full web addresses, and pictures add a schema.org `ImageObject` to the page's structured data.

## For developers

- Module `block` (required, needs `site` + `media`). The `page` module now needs it.
- Add a block type with `$registrar->blockTypes()->add(MyBlock::class)` (contract `SightPress\Module\Block\BlockType`).
  Names of other modules' types need a vendor prefix: `acme.event_card`.
- Schemas are JSON Schema (draft 2020-12), limited to what core can enforce: `type`, `properties`, `required`,
  `additionalProperties`, `enum`, `minLength`, `maxLength`, `pattern`, `minimum`, `maximum`, `items`, `minItems`,
  `maxItems`, `format` (`link`, `email`, `media`, `media-image`, `media-video`, `media-audio`, `media-document`)
  and `contentMediaType: text/html` (rich text, sanitised on save). Any other keyword throws when the type is first
  used, so a rule is never skipped silently.
- Services: `BlockValidator` (checks, upgrades old versions, sanitises, runs `a11yRules()`, throws `InvalidBlocks`
  with JSON Pointers), `BlockRenderer` (HTML, Markdown, schema.org nodes; unknown types are left out) and
  `BlockContexts` (the `BlockContext` for a locale), all in `SightPress\Core\Block\Contract`.
- `PageEditor` validates the blocks on every save; accessibility issues don't stop a save, errors stop publishing
  (C-4.5).

## Still to write in the docs

- Reference: every core block type with its fields and limits; the schema keywords for module authors.
- How-to (non-developers): add, move and remove blocks; write good alt text and link text.
