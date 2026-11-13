# 0006 — Page workflow states

- Status: Proposed
- Date: 2026-11-13

## Context

Sites have three seeded roles: admins, editors and contributors. Contributors write pages but must not publish
them; editors review and publish. Pages also need to be taken offline, kept aside and deleted safely. Every change
of state has to be audited and may trigger emails, cache purges, search indexing and sitemap updates.

## Decision

- Model the page lifecycle as a state machine with these states: `draft`, `in_review`, `approved`, `published`,
  `archived`, `trash`.
- Allow these transitions:

  | Transition  | From                                          | To          |
  |-------------|-----------------------------------------------|-------------|
  | `submit`    | `draft`                                       | `in_review` |
  | `reject`    | `in_review`                                   | `draft`     |
  | `approve`   | `in_review`                                   | `approved`  |
  | `publish`   | `approved`                                    | `published` |
  | `unpublish` | `published`                                   | `draft`     |
  | `archive`   | `draft`, `in_review`, `approved`, `published` | `archived`  |
  | `restore`   | `archived`                                    | `draft`     |
  | `trash`     | any state except `trash`                      | `trash`     |
  | `untrash`   | `trash`                                       | `draft`     |

  Purging a trashed page deletes it for good and is not a state.
- Guard every transition with RBAC permissions (for example `page.submit`, `page.publish`, `page.archive`,
  `page.delete`) plus voters for ownership. Contributors can submit their own pages but can't approve or publish;
  editors and admins can approve and publish.
- Run the publish checks (accessibility errors block, SEO warnings don't) before `publish`.
- Emit a domain event for every transition. Audit, notifications, cache purge, search and sitemaps listen to the
  events after the transaction commits.
- Implement it with a workflow library behind a core service, so domain code doesn't depend on the library.
- `clone` is not a transition: it creates a new page in `draft`.

## Consequences

- Editors go through `submit` and `approve` for their own pages too. If that proves slow, a later ADR can add a
  direct `draft → published` transition for roles with `page.publish`.
- Only `published` pages are ever public, indexed or listed in sitemaps.
- Scheduled publishing (later) is a timed `publish` from `approved`.
- Adding a state later means a new ADR, because modules and themes may depend on these names.
- Trashed pages answer 410 until they are purged.
