# 0055 — Direct-to-bucket uploads

- Status: Proposed
- Date: 2026-11-23

## Context

D39 recommends sending large files from the browser straight to the site's bucket, so they never pass through PHP or
the web server. The temporary folder (ADR-0054) stays as the fallback.

## Decision

- **Use S3 multipart with presigned `UploadPart` addresses,** in batches of 20, valid for 15 minutes. Parts are 8 MB or
  larger, in whole megabytes, and a file never needs more than 9,999 of them.
- **Ask the bucket before using it.** A CORS preflight from the site's address decides the mode, and is remembered for
  ten minutes. If the bucket refuses, can't be reached, or hides the `ETag` from the browser, the upload goes through
  the temporary folder with a one-line notice. The storage screen says which mode is in use and why.
- **Allow the bucket in `connect-src` only on the media library.**
- **Trust nothing the browser reports.** `ListParts` must show every part at its exact size with the same ETag before
  the parts are joined.
- **Check the file after it arrives,** after the response, or from `schedule:run` if that stops: size, real type from
  the first megabyte, streaming SHA-256, re-encoding for pictures, then a copy to the final key. A refused file is
  deleted from the bucket.
- **Abort on cancel, expiry and `site:purge`,** so no invisible parts stay in the bucket.

## Consequences

S3 can't sign the size of a presigned request, so someone allowed to upload can store a part larger than announced
until the upload is cancelled or expires. Such a part is never joined into a file. The bucket's lifecycle rule (M-3) is
the backstop for parts left behind. Only MinIO has been tested live, not AWS S3 or Cloudflare R2.
