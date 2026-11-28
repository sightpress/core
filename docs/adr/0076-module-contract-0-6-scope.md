# 0076 — What `module-interface` 0.6 adds: privacy, placeholders, code languages and locale formats

- Status: Proposed
- Date: 2026-11-27

## Context

Stage 5 changes the module contract in four places: privacy (GD-1 … GD-7 need modules to declare their personal
data, cookies and processors), placeholders (BL-6), the `code` block's languages (BL-4), and locale formats and
single-locale sites (I-9, I-10). Modules depend on `module-interface` only (ADR-0009), so each contract has to be
released before core uses it. Grouping them gives one interface release for the stage (MI-0.6), as ADR-0057 did for
0.5.

## Decision

| Area | Contract in 0.6 | ADR |
|---|---|---|
| Personal data inventory | `privacy()` registrar; `Privacy\PersonalDataProvider` (`describe()`, `export(UserRef)`, `erase(UserRef)`), `PersonalDataSet`, `Retention`, `ErasureResult`, `UserRef`, enums `PersonalDataCategory`, `LawfulBasis`, `ErasureRule`; `#[PersonalData]` attribute | 0077 |
| Cookies and browser storage | `Privacy\CookieDeclaration` + `StorageKind` | 0077 |
| Processors | `Privacy\ProcessorDeclaration` from a module's `ProcessorSource`, asked each time so a declaration exists only while the feature is configured | 0077 |
| Consent | `Privacy\ConsentPurpose` + `ConsentManager` (core-implemented, in the site container) | 0077 |
| Privacy events | `Event\UserDataExported`, `UserErasureScheduled`, `UserErasureCancelled`, `UserErased` (ids and dates only) | 0077 |
| Placeholders (BL-6) | `placeholders()` registrar; `Placeholder\Placeholder`, `PlaceholderType`, `PlaceholderResolver`, `PlaceholderContext` | 0078 |
| Code languages (BL-4) | `BlockTypeRegistrar::addCodeLanguage()`; `Block\CodeLanguage`, `CodeHighlighter`, `CodeToken`, `CodeTokenKind` | 0079 |
| Locale formats (I-9) | `Locale\LocaleFormats`, `FormatStyle`, `NameWidth`; `Money\Money` + `MoneyFormatter` | 0080 |
| Single-locale sites (I-10) | `Site\SiteLanguages` (`isMultilingual()`, `locales()`, `default()`) | 0080 |
| Outbound HTTP (GD-1) | **Keep** a documented service: modules keep asking for `HttpClientInterface`; in GD-1 core hands them the `OutboundHttp` client, which only reaches hosts an enabled processor declares | — |
| Session stores (H-9) | **Keep** internal: modules store nothing in the session store directly | — |
| Personal data in events | **Keep** the 0.5 rule: events carry ids and plain values, never emails, names or numbers | — |

- Ship all of it as one additive minor release, `module-interface` `0.6.0`. Only core implements
  `ModuleRegistrar`, `BlockTypeRegistrar`, `PrivacyRegistrar`, `PlaceholderRegistrar`, `ConsentManager`,
  `PlaceholderContext`, `LocaleFormats`, `MoneyFormatter` and `SiteLanguages`, so adding methods there is a minor
  change; modules implement `PersonalDataProvider`, `ProcessorSource`, `PlaceholderResolver` and `CodeHighlighter`.
- Require `^0.6` in core in one PR after the tag. That PR only collects the new registrations in the module
  registry; GD-1, BL-4, BL-6, I-9 and I-10 use them.

## Consequences

- Existing modules keep working: every addition is new, and nothing in 0.5 changes shape.
- GD-1 … GD-7, BL-4, BL-6, I-9 and I-10 start from a released contract, and the API freeze (H-7) includes it.
