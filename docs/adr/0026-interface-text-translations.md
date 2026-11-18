# 0026 — Interface text is translated from XLIFF catalogues, with messages instead of built sentences

- Status: Proposed
- Date: 2026-11-18

## Context

I-3 asks for every admin and front-end string to go through the translation layer, with `en-GB` as the source and
European Portuguese and Spanish to start, a lint for missing and unused keys, language switchers, and emails in the
recipient's language. Templates already called `t()` with the en-GB text as the key, but PHP built many sentences with
`sprintf()` (flash messages, validation messages, exceptions shown in forms, publish issues), which can't be looked up
in a catalogue once the values are in. Symfony's XLIFF loader needs `symfony/config`, which core doesn't use.

## Decision

- **Messages, not built sentences.** `Template\Message` is an ICU message (`Saved “{title}”.`, plurals with
  `{count, plural, …}`) with its values; values can be messages too (translated with it) and `LanguageName`s (a
  language named in the language the text is shown in). Validation messages keep the validator's `{{ limit }}`
  values. Form fields, form errors, flash messages (kept in the session as plain arrays), redirects, publish issues,
  block violations and the exceptions people read (`TranslatableText`) carry `string|Message`; templates show them with
  `t()`. Lists use `Message::list()` ("a, b and c" from `{first}, {rest}` and `{first} and {last}`). Cast to a string,
  a message is its en-GB sentence, for logs and the command line, which stay in en-GB.
- **Catalogues.** XLIFF 2.0 files in `resources/translations/messages.<locale>.xlf`, read by core's own
  `Template\XliffLoader` (no new dependency). The source and key is the en-GB text. Shipped: `en-GB`, `pt-PT`, `es`.
  A locale without a catalogue uses the closest one (`LocaleFallback`: `pt-BR` → `pt-PT`, `en-US` → `en-GB`), then
  the tenant's admin language.
- **Lint.** `tools/translations.php check` (in `composer check`) finds every `t('…')` in templates, every
  `new Message('…')` and the literal texts given to the places that show text (fields, settings, permissions, menu
  items, accessibility and publish issues, validation messages, flashes, redirects, error pages, the exceptions people
  read, block labels and schema titles, `label()` methods). It fails on a missing or unused key, a shipped catalogue
  without a translation or with other placeholders, and on text built at run time in one of those places.
  `update` writes the en-GB catalogue and adds or removes units in the others.
- **Which language.** The admin panel: the person's own language, else the tenant's admin language (en-GB), never the
  site's. A public page: its own language (menus, buttons and the switcher too). Other screens on a site: the person's
  language, else the site's. Error pages: the person's, else the language part of the address, else the site's, else
  en-GB. Emails: the recipient's language, else the site's, then en-GB through the catalogues (ADR-0007).
- **Switchers.** The admin header has a Language select (each language in its own words, with `lang`), posting to
  `/account/language`; *My account* offers the site's languages and the admin translations the same way. Public pages
  with more than one published translation have a "Languages" navigation linking to each, in its own words, with
  `lang`, `hreflang` and `aria-current` on the current one.

## Consequences

- Sentences built from fragments ("Moved {what} {action}") read less well in other languages; they are kept small and
  can be split into full sentences when a reviewer asks.
- The audit log keeps the en-GB sentence it recorded when the change happened; its screen around it is translated.
- Text passed to `t()` through a variable that the extractor can't see isn't checked; such places use
  `new Message('…')` so it can.
- Modules can't ship catalogues yet; that needs a registrar in `module-interface` (a later minor).
- A fluent speaker reviews `pt-PT` and `es` before the release.
