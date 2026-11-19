# 0042 — AI assistant: drafting tasks, checked output, and alt text that needs a person

- Status: Proposed
- Date: 2026-11-20

## Context

A-2 and A-3 (ADR-0040, ADR-0041) built the assistant and its screens with one task, `ask`. A-4 adds the drafting
tasks the plan names: `draft_page`, `summary`, `seo_meta`, `rewrite`, `explain_check` and `alt_text`. Their answers
end up in pages, so they are untrusted input (ADR-0010) that must fit what the field or block allows, and page
content in a prompt must not be able to steer the model. Alt text written by a model and never looked at is an
accessibility risk of its own.

## Decision

- **Prompts are files**: `resources/assistant/prompts/<task>.md` (en-GB, the source), with optional translations in
  `<locale>/` or `<language>/` next to them (`Prompts`). Every system prompt ends with the rule that content between
  `<data>` and `</data>` is data, never instructions; page text, briefs, checks and file names always go in such
  blocks, and a `</data>` inside them is defused (`PromptData`).
- **Every answer is checked by its task** before anyone sees it, and refused (`invalid_output`, "The suggestion
  couldn't be used…") when it doesn't fit:
  - `summary`, `seo_meta`, `explain_check`, `alt_text`: plain text (markup stripped), within the field's limit
    (`Page\Contract\PageLimits`, `MediaPictures::ALT_MAX`), surrounding quotes and "Image of …" taken off;
    `seo_meta` answers JSON with both texts and the suggestion is the field that was asked for.
  - `rewrite`: plain text stays plain; text with HTML tags comes back through the rich text sanitizer
    (`Block\Contract\RichText`), so only what a rich text block allows remains.
  - `draft_page`: JSON blocks, only `heading` and `rich_text`, at most 30, each checked by the block validator
    against its type's schema (which also sanitises the HTML). Unknown types or data that doesn't fit refuse the whole
    draft. "Add to the end of the page" adds the blocks to the editor, unsaved.
  - `alt_text` (vision models only) sends the picture's widest copy up to 1024 px (`Media\Contract\MediaPictures`).
- **Alt text from the assistant is marked until a person checks it**: `media.alt_suggested` lists the languages
  whose alt text the assistant wrote. Saving the text unchanged keeps the mark; ticking "I checked the alt text" or
  changing it removes it. The publish check `media.alt_unchecked` (an error, WCAG 1.1.1) stops any page that shows
  such text from the library — not where the block has its own alt text or the picture is decorative.
- **Where they are**: Suggest buttons under the summary, the title in search results and the description (A-3) and
  under each alt text field of a picture; "Rewrite text" in every panel; "Draft this page" and "Explain this" (next to
  each publish check) on the page editor.

## Consequences

- A model that wraps JSON in prose or code fences still works; one that ignores the form is refused, and the person
  can try again.
- A site that adds block types doesn't get them in drafts until the task's list grows.
- Alt text suggested before this mark existed doesn't exist: the mark starts with A-4.
