# 0043 — AI assistant: help answered from the documentation

- Status: Accepted
- Date: 2026-11-20

## Context

People ask the assistant how to use SightPress. A model on its own guesses, may describe screens the person can't
open, and doesn't know this version. The plan (A-5, gap 8) wants answers from the `docs` repository, without a vector
database or another service, pointing only to what the person can use and citing the documentation. Core and docs
are separate repositories released together.

## Decision

- **A help index in core**: `resources/assistant/help-index.json`, built by `tools/assistant-help-index.php` from the
  docs' Markdown (`HelpIndexBuilder`): one chunk per section (page title, heading, address on
  `https://docs.sightpress.org/latest/…` with the heading's anchor, text up to 1500 characters), `adr/` left out, in
  file order, so the same Markdown always gives the same file. Each chunk lists the permissions its screens and
  commands need (`SCREENS`: `**Features**`, `/admin/features` → `module.manage`, `bin/sightpress` →
  `settings.manage`, …); what a page's introduction needs, all of its sections need.
- **Kept in step at release time**: the `Help index` workflow rebuilds the index from docs `main` whenever the release
  pull request changes (and on demand) and opens a pull request when it changed; `--check` tells whether it is up to
  date.
- **Keyword search** (`HelpIndex`): the question's words (stop words out, plurals singular) count three times in a
  chunk's title or heading and once (up to five) in its text; chunks needing a permission the person lacks are left
  out; the best four go to the model.
- **The `help` task**: the prompt has those chunks as `<data>`, the screen the person is on and the permissions they
  have, and says to mention only what they can use and to cite the documentation. An answer without a documentation
  address gets the best chunk's address (or the documentation's home) added.
- **The panel** sends a question to `help`, unless the person shares the page, which goes to `ask` (A-2).

## Consequences

- New documentation pages that name a screen in another way than `SCREENS` knows aren't filtered until the list
  grows; the prompt still names the person's permissions.
- Answers link to `latest`, which matches the release only while it is the newest.
