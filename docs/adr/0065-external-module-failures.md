# 0065 — A broken external module doesn't stop the tenant

- Status: Accepted
- Date: 2026-11-24

## Context

Until now `ComposerModuleDiscovery`, `ModuleLoader`, `ModuleCatalogue` and `ModuleRegistry` threw during kernel boot
for any module problem (ADR-0009). With external modules installed from Packagist (M4), one package with a typo in
`extra.sightpress`, a class that doesn't autoload, a release for another contract version or an exception in
`register()` would make every site of the tenant answer 500. Themes already handle this safely: an unusable theme is
listed as unavailable with the reason (T-1). Stage 4 gap 3 asks for the same for modules.

## Decision

- A module is **external** when it comes from a Composer package (its candidate carries a contract constraint).
  Core's own modules keep failing loudly: a problem there is a broken install, not a third-party package.
- Every problem with an external module makes it **unavailable** instead of throwing: a broken declaration, a class
  that can't be created, an invalid manifest, an unmet contract constraint, an id clash, a missing / unavailable /
  wrongly versioned required module, a requirement loop, an "always on" module that needs an optional one, and any
  exception while it registers. What it registered before failing is rolled back; modules that need it become
  unavailable too.
- `UnavailableModule` (package, id when known, reason) is kept by the `ModuleCatalogue`. The *Features* screen,
  `site:module:list` and `system:check` (exit 1) show it; the log gets it once per change of the list (remembered in
  the cache folder).
- `modules.defaults` / `modules.locked` entries naming a module that isn't installed or is unavailable are ignored and
  reported by `system:check`, rather than stopping the tenant. A site's own choice for a removed module stays in
  `site_module`, so reinstalling the package brings it back on with its data; the *Features* screen lists it as
  removed meanwhile.
- What packages declare is cached (`modules.php` in the cache folder, keyed by the hash of `installed.json`), like
  themes, so discovery doesn't parse `installed.json` on every request.

## Consequences

- One bad package can no longer take every site down; operators find out from the admin, the CLI, `system:check` and
  the log.
- A module's failure is visible but not fatal, so operators must watch `system:check` (or the log) after updates.
- The order of boot stays the same; the extra work (one `clone` of the registry per external module) is negligible.
