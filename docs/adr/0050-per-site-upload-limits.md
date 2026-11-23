# 0050 — Per-site upload limits, the upload probe and refused-upload counts

- Status: Proposed
- Date: 2026-11-23

## Context

M-5a made uploads fail clearly. M-5b lets each site have its own limits under a tenant ceiling (D42), checks the web
server's real limit, tells admins about refused uploads, and lets them hide the notice until its result changes.

## Decision

- **Limits are layered:** `MediaLimits` defaults, then `media.limits` in `config/tenant.php`, then the site's override
  in `config/sites.php`. A value with no `media.limits.max` can't rise above the tenant default. An override above
  the ceiling is refused when it is written.
- **Registry format 2** adds an optional `media_limits` key, and `site:registry:upgrade` moves version 1 files. An older
  file *without sites* is still read, because it has nothing to convert: the skeleton ships one, and changing that
  tracked file would make `git pull` conflict on every install that has added a site.
- **`UploadLimits` is shared code** (`Http\Upload`), registered by the site container, so the user, admin and branding
  modules can use it without depending on the media module.
- **The probe measures the web server alone.** A signed POST (HMAC with `APP_SECRET`, one body size, five minutes) is
  answered by a middleware before the site, rate limit, `post_max_size` and CSRF checks, and its body is never read.
  Any other request to that address gets a 404.
- **Only the web server can count a refusal.** nginx sets the `SIGHTPRESS_BODY_REFUSED` FastCGI parameter on its internal
  413 location, which a visitor can't set. The count is one number per day, with no personal data, kept for 30 days.
- **A refusal is a note in `system:check`, not a failure,** because anyone can send one oversized request.
- **Hiding the notice** stores a fingerprint of its result per site. Free space and the refusal count are left out, as
  they change all the time.

## Consequences

Installs with sites must run `site:registry:upgrade` when they update to this version; until then SightPress stops with
a message that names the command. Web servers other than the skeleton's nginx don't count refusals unless they set the
same parameter.
