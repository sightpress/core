# 0020 — Demo content is a flag on site provisioning, and steps can share ids

- Status: Accepted
- Date: 2026-11-17

## Context

C-8.1 crawls a demo site in the accessibility job: every public page and every admin screen as each role. That site
needs one page with every kind of block, the media files those blocks use and an account for each role. The same
content helps anyone trying SightPress out, so it belongs in `site:create`, not only in a test fixture.

The content spans several modules (media admin, auth, page, menu), and each module adds its own part through the
`SiteProvisioning` event (ADR-0009: no back doors). The page step needs the ids of the media files and of the demo
contributor that earlier steps made, and the menu step needs the page's id. The contract `Site\Contract\SiteProvisioning`
had no way to say "demo wanted" or to pass ids between steps.

## Decision

- Add `site:create --with-demo-content`. `SiteProvisioning` gets a readonly `bool $demo` (last constructor argument,
  default `false`), so existing callers keep working.
- Add the priority `SiteProvisioning::DEMO` (-100): demo steps run after every normal step, in the order media files
  (-100), accounts (-110), pages (-120), menu entries (-130). Each one does nothing unless `$event->demo` is true.
- Add `SiteProvisioning::share(string $name, int $id)` and `shared(string $name): ?int`, so a step can hand the id of
  something it made to later steps (names like `demo.picture`, `demo.page`). The ids live only for the one event.
- Demo files ship in core's `resources/demo/`; they are made for SightPress, with no third-party material.
- Demo accounts are pending like the first admin and get printed setup codes (ADR-0017); no password is ever set by
  the command.

## Consequences

- Any module (also an external one) can add demo content of its own by listening at a priority below `DEMO`.
- `shared()` returns `null` for an unknown name; steps that need an id must check it and fail clearly.
- The demo text is in English, stored under the site's default locale whatever it is; translated demo content can
  come later if people ask for it.
