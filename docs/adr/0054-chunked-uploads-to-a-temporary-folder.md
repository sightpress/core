# 0054 — Chunked uploads to a temporary folder

- Status: Accepted
- Date: 2026-11-23

## Context

D39 decided that large files are sent in resumable parts. Direct-to-cloud is the recommended path (M-8). Sites without
cloud storage, or whose bucket can't take browser uploads, need a fallback that works behind a small web server limit.

## Decision

- **Speak tus 1.0** at `/admin/media/uploads` (creation, termination, checksum) with a small client of our own.
- **Make each part all or nothing.** The stored offset is the end of the last good part. A cut-off or damaged part is
  truncated away, and a lock stops two requests writing at once.
- **Check the file only once, at the end,** through `MediaUploader`. What the browser announces only refuses a file
  early. A refused file is deleted.
- **Keep waiting files outside `public/`:** `uploads.temp_path` (default `var/uploads`) is refused inside it.
- **Limit each person** to 5 unfinished uploads and twice `uploads.max_chunked_bytes` in total, and check free disk
  space before the first part.
- **Give parts their own rate limit** (`upload_part`, 600 a minute per address). One file is hundreds of requests and
  would otherwise use up the 60-a-minute `write` limit. Every other request keeps that limit.
- **Send the CSRF token in `X-CSRF-Name` and `X-CSRF-Value`.** The guard's own header names contain underscores, which
  nginx drops by default.
- **Save a session only when it changed, and atomically.** Overlapping requests otherwise lost CSRF tokens or read a
  half-written session, which signed the person out in the middle of an upload.

## Consequences

Sessions are deleted 24 hours after their last part, or 7 days after finishing. Several web servers need a shared
`uploads.temp_path`; without one, the person is told so. `media_upload` holds a user id and a file name (personal data)
until then.
