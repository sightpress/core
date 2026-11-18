# Interface languages (I-3)

The words SightPress itself shows — the admin panel, sign-in pages, forms, error pages, emails — are translated. It
ships in British English (the source), European Portuguese and Spanish.

## For people using a site

- **Admins and editors** choose the admin panel's language with the **Language** list at the top of every admin
  screen, or in *My account*. Each language is written in its own words ("Español", "Português europeu").
- **Visitors** see a page's menus, buttons and messages in the page's language, and a **Languages** list at the top of
  pages that are translated, to read the same page in another language.
- **Emails** (codes, invitations, review requests, contact messages) are in the recipient's language, otherwise the
  site's main language. They still never contain a link.
- Languages without a translation of their own use the closest one (Brazilian Portuguese uses European Portuguese),
  otherwise British English.

## For developers

- Write sentences with values as `new Message('Saved “{title}”.', ['title' => $title])`, never `sprintf()`; plurals as
  `{count, plural, one {# page} other {# pages}}`. Templates: `{{ t('Text') }}` or `{{ t(message) }}`.
- Catalogues: `resources/translations/messages.<locale>.xlf` (XLIFF 2.0, the en-GB text is the key).
- `composer translations` (part of `composer check`) fails on missing, unused or untranslated texts and on text built
  at run time; `php tools/translations.php update` refreshes the catalogues after adding texts.
- ADR-0026. Tests: `tests/Integration/Template/TranslationLintTest.php`, `tests/Integration/Template/AdminInSpanishTest.php`,
  `tests/Unit/Auth/PasswordResetTest.php` (emails in every shipped language, no links), `tests/A11y/languages.spec.ts`,
  `tests/A11y/translations.spec.ts`.

## Review

A fluent speaker of European Portuguese and of Spanish reviews the catalogues before the 0.2.0 release.
