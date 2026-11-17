# 0018 — The page editor builds its fields from block schemas

- Status: Accepted
- Date: 2026-11-17

## Context

The page editor (C-7.2) has to edit every block type, including those of modules that core doesn't know. The admin
is server-rendered without a single-page app (ADR-0004), and the block editor must work by keyboard and with
screen readers: add, move and remove blocks with buttons and keep focus in place (D4). The editor lives in the
Page context (module `page_admin`) and may only use other modules through their contracts (ADR-0009), but it
needed three things that weren't public: the meaning of the `format` values in block schemas (inside the Block
module), a list of media files to choose from, and the id of a media file.

## Decision

- The editor makes one control per field of a block type's JSON Schema (ADR-0005): text, text area (more than 300
  characters), HTML text area with a preview (`contentMediaType: text/html`), select (`enum`, or whole numbers with a
  small range), checkbox (boolean), number, email, a select of media files (`format: media-*`), a list of
  fieldsets for an array of objects (pictures of a gallery, columns) and a nested block list for an array of bare
  objects (the blocks of a column). The schema's `title` is the field's label and `description` its hint; core's
  block types now have both. Field names and ids come from the JSON Pointer of the value, so errors and publish
  checks (`PublishIssue::anchor()`) link straight to the field.
- The buttons that add, move or remove a block (or a picture, a column) send the whole form with `op`, for example
  `up:/blocks/2`. The server applies it to the posted blocks without saving, says what changed in the live
  region and puts focus on the block, or on the same button where it moved. Only "Save page" writes a revision.
- New public contract `SightPress\Core\Block\Contract\BlockSchema` with the `format` values and the rich text media
  type; the Block module's `SchemaConstraints` uses the same constants.
- `MediaLibrary` gets `choices(?MediaKind $kind, ?string $mimeType, int $limit)`: files to choose from, newest
  first. `MediaFile` gets `id()`, the id blocks store.
- The Page module's `PageAddresses` becomes a public service, for the admin's page list and editor.
- `Form::has()` tells whether a form has a field (the share image field is left out while the library has no
  pictures).

## Consequences

- A module's block type is editable as soon as it is registered, with no editor code; it should give its fields
  a `title` and a `description`. Fields of kinds the editor doesn't know get a text field.
- Every structural change is a round trip to the server. That is slower than a script, but works without
  JavaScript, keeps one source of truth and is announced the same way in every browser and screen reader.
- A block whose type isn't installed is kept as it is (its data travels as JSON in a hidden field) and can only be
  moved or removed.
