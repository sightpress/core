# Site languages and translations (I-1)

A site can be written in several languages. Admins choose them in **Site settings → Change the languages**
(`/admin/settings/languages`, `settings.manage`) or with the CLI; editors translate each page from its editor.

## Who and what

- **Admins** add a language (chosen from every language the server knows, such as "Spanish (es)"), make one the main
  language (its pages have addresses without a language part; the others start with `/<code>/`, lower case), and
  remove one after a confirmation page. Removing keeps the translations, unreachable: adding the language again
  brings them back. People who chose a removed language for their emails and the admin get the main language.
- **Editors and writers** see a "Translations" table in the page editor: each language with its status (*Not written
  yet*, *Draft*, *Draft translation*, *Published*). "Translate into <language> from <language>" copies the text and
  blocks into a new draft translation and opens it; the page in the other language doesn't change. A page can also be
  translated from scratch.
- **Editors and admins** (`page.publish`) publish a translation on its own with "Publish this translation" (after the
  publish checks of that language) or make it a draft again. Visitors see a translation once it and its page are
  published. Draft translations are not in menus, `hreflang`, the sitemap or `llms.txt`.
- **Media library:** alt text, caption and transcript per language. A block that leaves them empty uses the
  library's text in the page's language, or else the main language's.

## CLI

- `site:locale:add <site> <locale>` — e.g. `site:locale:add demo pt_pt` (written as `pt-PT`).
- `site:locale:remove <site> <locale> --yes` — without `--yes` it only explains what would happen.
- `site:locale:default <site> <locale>` — warns that addresses change.

Every change is in the audit log ("Changed the site settings: Languages.", "Published the Spanish translation of …")
and the `sites` log.

## Accessibility

Native `<select>` for the language list (type the first letters to move), every button and link names its language,
the language names in the tables carry their `lang`, the removal is confirmed on its own page, and every change is
announced in the status region. Playwright: `tests/A11y/languages.spec.ts`.

## Code and tests

`src/Site/Contract/SiteLanguages.php`, `src/Site/Command/*SiteLocaleCommand.php`, `src/Admin/Http/LanguagesHandler.php`,
`src/Admin/Http/RemoveLanguageHandler.php`, `src/Page/Admin/Http/PageTranslationHandler.php`,
`src/Block/Contract/MediaTexts.php`; ADR-0024. Integration: `tests/Integration/Site/SiteLanguagesFlowTest.php`.
