# 0077 — Privacy contract for modules: personal data, cookies, processors and consent

- Status: Proposed
- Date: 2026-11-27

## Context

ADR-0075 lists core's personal data and decides that every module, core's own included, declares what it stores,
the cookies it sets and the third parties it sends data to. The *Download your data*, *Delete your account*,
Privacy Policy, cookie list and record of processing (GD-3 … GD-5) are built from those declarations, so the
contract must carry everything they print and everything erasure needs, without core knowing a module's tables.

## Decision

- Add `ModuleRegistrar::privacy(): PrivacyRegistrar` with `addProvider()`, `addCookie()`, `addProcessors()` and
  `addConsentPurpose()`.
- **Data sets.** A module implements `Privacy\PersonalDataProvider` once per data set:
  - `describe(): PersonalDataSet` — a dotted key (`acme_events.attendees`), label, purpose, one or more
    `PersonalDataCategory` (identity, contact, account, technical, usage, communications, consent, content), the
    *suggested* `LawfulBasis` (the six of Art. 6(1)), `Retention` (days, or while the account exists, and the
    setting that changes it) and the `ErasureRule` (delete, pseudonymise, keep).
  - `export(UserRef): iterable` — the person's records as JSON-safe arrays (scalars, null, nested arrays). Core
    writes them to `data.json` and `data.html` under the data set's label.
  - `erase(UserRef): ErasureResult` — deletes or pseudonymises the person's records **and files**, idempotently
    (an erasure re-applied after a backup restore must work), and returns counts only.
- `UserRef` carries the account id, email and (if any) mobile number in E.164, because some data is keyed by email
  or number (invitations, code hashes). It is personal data: it masks itself in dumps and can't be serialised, like
  `Sms\PhoneNumber`.
- `#[PersonalData(dataSet, category)]` marks an entity property or promoted constructor parameter that holds
  personal data; core's check (GD-1) fails when its data set has no provider.
- **Cookies and browser storage.** `CookieDeclaration`: name, purpose, lifetime in seconds (null for a browser
  session), strictly necessary or not, and the `StorageKind` (cookie, local storage, session storage — the law
  treats them alike). One that is not strictly necessary must name the `ConsentPurpose` it waits for; core never
  sets or allows it before consent.
- **Processors.** `ProcessorDeclaration`: an id, who (company name), what is sent, the countries where it is
  processed (ISO 3166-1, so transfers outside the UK/EEA show up), the hosts the module calls, an optional privacy
  policy address and the transfer safeguards. A module registers a `ProcessorSource` class; core asks it each time,
  so it returns a declaration only while the feature is configured (an SMS provider without a key declares
  nothing). GD-1's `OutboundHttp` allows only the declared hosts.
- **Consent.** `ConsentPurpose` (key, label, description) and `ConsentManager` in the site container:
  `purposes()`, `allows(request, purpose)` for a visitor's choice (false until they choose; `Sec-GPC: 1` counts as
  *no*), `hasConsented(userId, purpose)`, `record(userId, purpose, textVersion)` and `withdraw(userId, purpose)`
  for opt-ins stored in `consent_record` (GD-7). Core ships no banner while nothing needs one (D49).
- **Events** (ids and dates only, like 0.5): `UserDataExported`, `UserErasureScheduled` (with when it happens),
  `UserErasureCancelled`, `UserErased`. Modules use them to stop work for the person; the data itself is erased
  through the provider.

## Consequences

- A module that stores a user id without a provider fails core's check, so nothing is missed when a person asks for
  their data or to be erased.
- Retention for a module's own data is enforced by the module (a `ScheduledJob`); core prints it in the policy and
  record. A shared purge hook can be added in a minor release if modules need one.
- Lawful bases stay suggestions; the owner can change what the record of processing prints (GD-5).
