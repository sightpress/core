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

None yet. Task S-5 adds the template and the first ADRs.
