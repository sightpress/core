# 0079 — Code languages for the `code` block

- Status: Proposed
- Date: 2026-11-27

## Context

BL-4 adds the `code` block (`blocks.md` §1.3) with server-side highlighting from `tempest/highlight` (gap 9, still
to be checked in BL-4). Modules should be able to add languages the library doesn't know, without the contract
depending on that library.

## Decision

- Add `BlockTypeRegistrar::addCodeLanguage(CodeLanguage)`.
- `Block\CodeLanguage`: an id (`twig`, `c++`, `f#`), a label (the language's own name, not translated), aliases
  (`yml` for `yaml`) and an optional `CodeHighlighter` class.
- `CodeHighlighter::highlight(code, language)` returns `CodeToken`s (text + an optional `CodeTokenKind`: keyword,
  type, name, string, number, comment, operator, punctuation, attribute, variable, tag). Joined, the tokens' text is
  the code exactly; core checks that, escapes every token and styles kinds with its fixed palette, which never
  relies on colour alone. A highlighter never returns HTML.
- A language without a highlighter is shown as plain, escaped text.

## Consequences

- Core's own languages come from the library behind the same shapes, so a module language and a core language
  look alike in the block.
- Ids are unique per site; registering one that exists is an error naming both modules.
