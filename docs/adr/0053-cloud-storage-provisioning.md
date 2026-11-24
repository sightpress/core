# 0053 — Cloud storage provisioning

- Status: Accepted
- Date: 2026-11-23

## Context

M-3 sets up a site's bucket, browser-upload rules and CDN at AWS or Cloudflare. M-2 protects private files by having
the site check a signature on every request, so a CDN or public domain that reads the bucket directly would bypass it.

## Decision

- **One service, `StorageProvisioning`,** behind `site:storage:provision` and the admin storage screen.
- **Provider keys come from the server's environment,** are used for one run and are never saved. The site keeps its
  own narrower keys, set with `site:storage:s3`.
- **Provisioning never switches a site** to the new storage or CDN. `site:storage:migrate` and `site:storage:cdn` do.
- **CloudFront pulls `/media/*`, `/themes/*` and `/assets/*` from the site,** with the query in the cache key and no
  cookies. Everything else goes to S3 through Origin Access Control, whose bucket policy allows only
  `<prefix>/public/*` for that one distribution. Private files are never stored there.
- **An R2 custom domain exposes a whole bucket,** so it is only attached to a separate `<bucket>-public` bucket.
- **CORS allows `PUT` from the site's own https origins and exposes `ETag`.** Lifecycle rules abort unfinished uploads
  after 1 day and expire `<prefix>/incoming/` after 2 days.
- **Re-running is safe.** Rules, policy statements and CDN settings from other tools or sites are kept. An existing
  distribution is reused and not edited.
- **Default location (D50) follows the site's time zone,** because core records no owner country: UK → `eu-west-2`,
  EU/EEA/Switzerland → `eu-central-1`, R2 → the `eu` jurisdiction (R2 has no UK location). Elsewhere `--region` is
  required. Any other choice, and CDN caching abroad, is named as an international transfer.
- **The admin screen is off by default.** It needs `SIGHTPRESS_PROVISION_FROM_ADMIN=1` and the provider's keys, because
  a site admin would otherwise be able to create billable resources in the operator's account.

## Consequences

The CDN adds no purge yet, so ADR-0051's five-minute limit stands. Nothing writes to `<prefix>/public/` yet. MinIO
has no public access block, CORS or multipart-abort rule, so those are only tested with mocks until the manual AWS
and R2 workflow is run.
