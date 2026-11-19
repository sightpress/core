# 0024 — Site languages live in the site, translations can be drafts of their own

- Status: Accepted
- Date: 2026-11-18

## Context

I-1 lets a site's admins add, remove and choose the main language of their site, in the admin panel and with
`site:locale:add|remove|default`. ADR-0019 kept the languages CLI-only, because only the CLI writes the tenant
registry (ADR-0002). Pages already had one translation per language (`page_translations`), but a translation had no
state of its own: a Portuguese translation started on a published page was public the moment it was saved, before
anyone had translated it. Media files had alt text and captions per language, but one transcript; and the alt text,
caption and transcript written in the media library were never used when a block left its own empty.

## Decision

- **Languages in the site settings.** `SiteLanguages` (`Site\Contract`) adds, removes and changes the main language.
  It stores `site.locales` (comma-separated, main language first) and `site.default_locale` in the site settings,
  which win over the registry inside the site (`SiteProfile::display()`, like the name and time zone in ADR-0019).
  This amends ADR-0019: languages are no longer CLI-only. The `site:locale:*` commands use the same service and then
  write the registry too, so `site:list` agrees. Locales are normalised with `LocaleCode::normalise()`; the admin
  screen offers every locale the server's ICU data knows (`LocaleCode::available()`).
- **Removing a language keeps its content.** It dispatches `Site\Contract\LocaleRemoved`. Translations in that
  language stay in the database but nothing reaches them: routing only knows the site's languages, and the page
  index, sitemap and publish checks leave other languages out. People who chose the language get the site's main
  language (`users.locale` set to null). Adding the language again brings everything back. The main language can't
  be removed. Removing needs a confirmation (the admin screen's confirmation page, `--yes` on the command line).
- **Translations can be drafts.** `page_translations.draft` (default false, so existing translations stay public).
  A translation added to a page that is already written in another language starts as a draft, also when it is
  copied with "Translate from <locale>" (`PageEditor::translate()`). A draft translation is only shown to people who
  can edit the page; it is left out of menus, `hreflang`, the sitemap and `llms.txt`. Publishing it on its own
  (`PageEditor::publishTranslation()`, `page.publish`) runs the publish checks of that language and dispatches
  `Page\Contract\TranslationPublished` (audit log, SEO cache purge). Publishing the page checks only the
  translations that aren't drafts. Revisions keep the flag.
- **Media texts per language, with a fallback.** `media.transcripts` (JSON, per locale) next to the old `transcript`
  column, which counts as the main language's transcript until one is written. `MediaFile::transcript()` now takes a
  locale (a contract change). `Block\Contract\MediaTexts` fills in what a block left empty (image and gallery alt
  text and caption unless decorative, video and audio transcripts) from the library in the page's language, then
  the main language's, never with empty text; text written in the block wins. The public page, its Markdown, the
  page index and the publish checks use it.

## Consequences

- A site's languages can differ between the registry and the site until the next `site:locale:*` command; inside the
  site, the settings win.
- Changing the main language changes the addresses of the pages in the old and the new main language; the screen and
  the command warn. Redirects for moved addresses come with P-1.
- Codes ICU knows but SightPress can't write (numeric regions such as `es-419`) can't be added yet.
- Modules that read `MediaFile::transcript()` must pass a locale.
