# 0058 — Public events in the module contract

- Status: Accepted
- Date: 2026-11-24

## Context

Core dispatches about 25 events through the site's PSR-14 dispatcher, all defined under core's `src/*/Contract/`. An
external module can only listen to them by depending on core (ADR-0057). Most already carry ids and plain values,
but some carry core enums (`PageTransition`, `PageState`, `MediaKind`, `MenuName`).

## Decision

- Move the events to `SightPress\Module\Event` in `module-interface` 0.5 and mark them part of the 1.0 API:
  `PageCreated`, `PageSaved`, `PageMoved`, `PageTransitioned`, `PageCloned`, `PagePurged`, `PageScheduled`,
  `ScheduledStepFailed`, `RevisionRestored`, `TranslationPublished`, `RedirectChanged`, `MediaUploaded`,
  `MediaChanged`, `MediaDeleted`, `MenuChanged`, `AccountRegistered`, `AccountChanged`, `RoleChanged`,
  `SettingsChanged`, `LocaleRemoved`, `SiteModuleChanged`, `ThemeApplied`, `ThemeColoursChanged`,
  `AnalyticsDataDeleted`, `AssistantUsed`. `SearchPerformed` stays in `Search`.
- Keep the names, the argument order and the property names, so core's change is mostly an import change.
- Carry ids and plain values only. Enums become strings, with the allowed values in constants
  (`PageTransitioned::TRANSITIONS`, `::STATES`, `MediaUploaded::KINDS`, `MenuChanged::ADDED` …), checked in the
  constructor. `PageTransitioned` gains `published()` and `unpublished()`, the two questions listeners ask most.
- Never carry personal data beyond account ids (no email addresses, names or phone numbers): `AccountChanged`
  lists the fields that changed, not their values.
- Dates are UTC (`PageScheduled`).
- Delete core's classes in the same PR that requires `^0.5`; core converts its enums with `->value` when it
  dispatches and `::from()` where a listener needs the enum.
- Keep internal: `RequestAnswered`, `ScheduleDue`, `SiteProvisioning`, `PublicPageRendering`,
  `PublicScreenRendering` (ADR-0057).

## Consequences

- New events are additive (minor release); changing or removing one is a major release.
- Modules written against 0.4 that listened to core's own event classes (none are published) would break; core's
  modules move in the same PR.
- Search providers still get no change event of their own (left for later in stage 4).
