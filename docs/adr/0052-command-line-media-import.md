# 0052 — Command-line media import

- Status: Accepted
- Date: 2026-11-23

## Context

Some files are too large for any browser upload. D40 decided that the command line has no size limit but keeps the
type and safety checks.

## Decision

- **Add `site:media:import`,** which goes through `MediaUploader::import()`: the same allow-list, content checks,
  picture re-encoding and dedupe as an upload, without the per-kind size limit.
- **Stream everything except pictures.** Hashing, type checks and storage writes never load the whole file. Pictures
  are decoded in memory, so they keep a pixel limit.
- **Let `--memory-limit` raise the pixel limit for one run.** A refused picture says how much memory it needs, at 11
  bytes a pixel.
- **Keep going after a refusal,** list every result in plain lines, and exit with an error if anything was refused.
- **Skip hidden files and symbolic links to folders** when walking a folder.
- **Add a core-only `TakesPaths` command type** for a list of paths. `module-interface` stays unchanged.

## Consequences

`--publish` and `--alt` apply to new files only: a file already present keeps its state. Importing very large files to
a bucket uses multipart upload through the AWS SDK.
