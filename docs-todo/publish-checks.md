# Publish checks: accessibility and search engines (C-4.5)

## What it does

Before a page is published, SightPress checks every language of it. Problems that make the page hard or impossible
to use for some people **stop it being published** until they are fixed. Things that would make it easier to find
are **suggestions**: the page can be published with them.

**Stop publishing (accessibility, WCAG 2.2 AA):**

- a picture without alt text that isn't marked as decorative;
- a video with speech but no captions, a video without speech with no description, audio without a transcript;
- a link with no text;
- a heading that skips a level (a level 4 straight after a level 2), or a level 1 heading in the content: the page
  title is the only level 1 heading;
- a language code that isn't real (`lang="english"` instead of `en`);
- a text or background colour chosen in the text that is too faint: it needs a contrast of at least 4.5:1, in
  light and dark mode and with more contrast. A dark text colour on the theme's dark background fails in dark
  mode: add a background colour that goes with it;
- a file the page uses that isn't published in the media library, or a block that is no longer valid (its type was
  removed, or its file was deleted);
- a language the site isn't written in any more.

**Suggestions:**

- link text that doesn't say where it goes ("Read more"), alt text that looks like a file name, a video without a
  transcript;
- no description for search engines, or one longer than 160 characters; no summary; a title another page already
  has in the same language.

When "Publish" is refused, the page editor shows the list at the top, with focus on it: each problem is a link to
the field or block it is about, with the language and the WCAG success criterion.

## For developers

- `PublishChecker::check($page)` returns a `Page\Contract\PublishReport` (`errors()`, `warnings()`,
  `canPublish()`) of `PublishIssue`s with locale, severity, kind (`accessibility` / `seo`), rule, message, JSON Pointer
  (`/title`, `/description`, `/blocks/2/data/alt`) and WCAG criteria. `PublishIssue::anchor()` is the `id` the page
  editor gives that field (`en-gb-blocks-2-data-alt`).
- `PageWorkflow` runs the checks on "Publish" and throws `PublishBlocked` (with the report) when there are errors.
- The checks combine each block type's `a11yRules()` with checks on the HTML each block renders, so blocks from other
  modules are checked too (headings, `alt`, `lang`, colours).
- Template `@page/publish-report.html.twig` (`report`, `focus`) renders the accessible list.
- New contract `Theme\Contract\PageColours` (the theme's text and background in each colour mode).

## Still to write in the docs

- Reference: every check, its message and WCAG criterion.
- How-to (non-developers): what to do when a page can't be published; choosing readable colours.
