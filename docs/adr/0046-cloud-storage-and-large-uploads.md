# 0046 — Cloud storage per site and how large files get there

- Status: Accepted
- Date: 2026-11-23

## Context

M3 lets a site keep its files in an S3-compatible bucket (AWS S3, Cloudflare R2, DigitalOcean Spaces, MinIO). D39 to
D42 decided how large files are uploaded, that the CLI has no size limit, and who sets limits. Moving a site must not
lose files, and one site's clean-up must never touch another's.

## Decision

- **Use `league/flysystem-aws-s3-v3` and `aws/aws-sdk-php`, both `^3`, as optional packages.** They are in `suggest`
  and `require-dev`. A site set up for S3 without them gets a message naming the package. The skeleton requires them.
- **Store the bucket in the site's registry entry** (`storage.s3`, part of registry format 2). Both keys are encrypted
  with `SecretBox`. Commands read them from `SIGHTPRESS_S3_ACCESS_KEY` and `SIGHTPRESS_S3_SECRET_KEY`, so they never
  reach the shell history or the process list.
- **Require a prefix, and refuse overlapping ones.** Two sites can share a bucket but not a folder of it, because
  `site:purge` deletes the site's whole prefix.
- **Connect first, switch later.** `site:storage:s3` checks the bucket by writing, reading and deleting a file. The
  site keeps its local files until `site:storage:migrate` has copied every file and compared SHA-256 checksums. Running
  it again resumes. Local copies are deleted only with `--delete-source`, and only once verified.
- **Show provider errors by their code only.** Their text can quote the key id.
- **`site:purge` removes the site's files:** its bucket prefix and its default local folder. It keeps a custom
  `storage.path`, and a folder that holds (or might hold) the site's SQLite database.
- **Admins read the storage status; operators change it.** The admin screen is read-only until M-3.
- **Large files (D39 to D42):** the browser uploads in chunks, directly to the bucket when it can (M-8) and through a
  temporary folder otherwise (M-7). The CLI has no size limit (M-6).

## Consequences

A file uploaded while a migration runs can stay local after the switch, so the command says to run it again. Until a
CDN or signed delivery is set up, files from a bucket stream through PHP, and a range request reads forward to its
start. Providers other than MinIO have not been tested live yet: M-3 adds that workflow.
