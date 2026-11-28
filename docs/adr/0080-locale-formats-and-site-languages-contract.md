# 0080 — Locale formats, money and site languages for modules

- Status: Proposed
- Date: 2026-11-27

## Context

D54 says dates, times, calendar names and numbers follow the locale the person chose (the default locale where there
is no choice), and money follows its own currency, never the locale. D55 says a single-locale site shows nothing
about languages. I-9 and I-10 build both in core; modules must format and hide language UI exactly as core does,
so the services are part of the contract.

## Decision

- `Locale\LocaleFormats` (core-implemented, in the site container, already set to the right format locale and time
  zone for the request or the recipient): `locale()`, `timeZone()`, `withLocale()`, `date()`, `time()`,
  `dateTime()` (with a `FormatStyle`: full, long, medium, short), `monthName()` and `weekdayName()` (ISO weekday
  1–7, a `NameWidth`: wide, abbreviated, narrow, and stand-alone or in-sentence forms), `firstDayOfWeek()` (ISO
  1–7), `number()`, `percent()` (a ratio: `0.25` → `25%`) and `fileSize()`.
- `Money\Money`: integer minor units and an ISO 4217 code, both required; no floats, no default currency, no
  conversion. `Money\MoneyFormatter::format(Money)` formats it by its currency alone, the same in every locale
  (gap 16).
- `Site\SiteLanguages` (core-implemented, in the site container): `isMultilingual()`, `locales()` (default first),
  `default()`. Modules hide every language control when `isMultilingual()` is false.
- Machine dates (JSON-LD, sitemaps, CSV, APIs) stay ISO 8601 and are not formatted with this service.

## Consequences

- I-9 implements `LocaleFormats` and `MoneyFormatter` around `intl` with one formatter cache; I-10 makes core's
  `Site\Contract\SiteLanguages` implement the contract and replaces every `count($site->locales)` check.
- `SiteInfo::$locales` stays; `SiteLanguages` is the one to use for decisions about showing language UI.
