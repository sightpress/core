# Architecture Decision Records

This folder holds the Architecture Decision Records (ADRs) for SightPress. They cover `core` and the
shared decisions for `module-interface`, `skeleton` and `docs`.

An ADR records one decision: the context, the decision and its consequences. It also has a status:
*Proposed*, *Accepted*, *Superseded by ADR-NNNN* or *Rejected*.

## Rules

- File names: `NNNN-short-title.md` (four digits, lowercase, hyphens), e.g. `0001-license.md`.
- Start from `0000-template.md`.
- A new ADR starts as *Proposed*. A maintainer sets it to *Accepted*.
- Never edit an accepted ADR's decision. Write a new ADR that supersedes it.
- Any change to a public contract (`src/*/Contract/` or the `sightpress/module-interface` package) needs an ADR first.

## Index

| ADR | Title | Status |
| --- | --- | --- |
| [0001](0001-license.md) | License: GPL-3.0-or-later | Proposed |
| [0002](0002-tenant-registry-database.md) | Tenant registry database | Proposed |
| [0003](0003-site-scoped-di-container.md) | Site-scoped DI container | Proposed |
| [0004](0004-server-rendered-admin.md) | Server-rendered admin panel (no SPA) | Proposed |
| [0005](0005-block-storage-json-typed-schemas.md) | Block storage as JSON with typed schemas | Proposed |
| [0006](0006-workflow-states.md) | Page workflow states | Proposed |
| [0007](0007-url-and-locale-strategy.md) | URL and locale strategy | Proposed |
| [0008](0008-accessibility-testing-strategy.md) | Accessibility testing strategy | Proposed |
| [0009](0009-module-architecture.md) | Module architecture | Proposed |
| [0010](0010-ai-assistant.md) | AI assistant | Proposed |
| [0011](0011-site-search.md) | Site search | Proposed |
| [0012](0012-analytics-privacy-model.md) | Analytics privacy model | Proposed |
| [0013](0013-framework-and-library-baseline.md) | Framework and library baseline | Proposed |

When an ADR's status changes, update it here too.
