# 0051 — CDN delivery and signed media addresses

- Status: Accepted
- Date: 2026-11-23

## Context

M-2 delivers a site's files through a CDN that pulls from the site. Public files should be cached. Private files, and
everything on a members-only site, must never be served to someone who may not see them.

## Decision

- **Set the CDN per site** (`storage.cdn_url`, https, no path) with `site:storage:cdn`.
- **Public files use the CDN host** with the first 16 characters of their SHA-256 in the address.
- **Sign with an HMAC** over the site id, file key, width and expiry, using a key derived from `APP_SECRET`. The CDN
  holds no secret: it forwards the query, and the site checks it. Expiry is rounded to 15 minutes so a page keeps its
  addresses, and a signature can't live longer than 75 minutes.
- **On a members-only site with a CDN or a bucket, every address is signed.** Those responses are `private, no-store`.
- **Never sign an unpublished file while rendering a page.** Only `temporaryUrl()` does, for admin screens whose reader
  may already see the file.
- **Cache published files for five minutes when a CDN is in front,** not a year. Nothing can purge a CDN yet.
- **Keep scripts and the admin panel's files off the CDN,** and leave the CDN out of `script-src`, so a compromised CDN
  can't run code on the site.
- **Publish theme files under a folder named after their checksum** (`public/themes/<id>/<version>/assets-<checksum>/`).
  One folder per set, rather than one name per file, keeps relative links between a theme's files working.

## Consequences

After a file is unpublished or a site becomes members-only, a CDN can keep serving a copy it already holds for up to
five minutes. M-3 can shorten this by purging through the provider's API. The CDN must include the query string in its
cache key, forward the site's host name, and must not forward cookies. A signed address works for anyone who has it
until it expires.
