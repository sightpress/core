# 0044 — AI assistant: translations drafted by the assistant, checked by a person

- Status: Accepted
- Date: 2026-11-20

## Context

I-1 starts a translation as a copy of the page for a person to translate. A-6 lets the assistant draft it instead.
A translation must keep the page's structure (blocks, links, media), and nobody should publish words a model wrote
without reading them. The assistant module may not reach into the page module's internals (ADR-0009).

## Decision

- **Only words are sent** (`TranslationSegments`): the title, summary, search title and description, then every
  text in the blocks (`text`, `html`, `alt`, `caption`, `title`, `transcript`, columns included), in a fixed order.
  The model answers `{"segments": [...]}` with one text per text; they are put back in the same places, so block
  types, media ids, link addresses in block settings, levels and styles never pass through the model.
- **Every text is checked** (`translate` task): the same number of texts; HTML through the rich text sanitizer with
  exactly the same link addresses; plain text within the field's limit. The page editor checks the blocks again when
  it saves the draft.
- **One request per page**, up to 60,000 characters; longer pages are refused with a hint to translate by hand and
  use Rewrite for parts.
- **Saved as a draft marked as the assistant's**: `page_translations.machine_drafted`, through the page contract
  `TranslationDrafts` (`PageEditor::draftTranslation()`), with an address made from the translated title. It is
  cleared only by a person: saving the translation in the editor, or **I checked this translation**
  (`PageEditor::confirmTranslation()`, a new revision). While it is set, the publish check
  `translation.machine_drafted` stops publishing that translation.
- **Shown as text** in the editor (a notice with the button, and the Translations table), the page list ("Translation
  drafted by the AI assistant, not checked yet: …") and the page's versions.

## Consequences

- Text inside block settings that isn't in the list (a future block's own field) stays in the original language
  until the list grows.
- A person who saves the translation without reading it clears the mark; the notice says that saving counts as
  checking.
