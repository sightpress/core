# Cloud storage

What it does: a site can keep its files in an S3-compatible bucket (AWS S3, Cloudflare R2, DigitalOcean Spaces, MinIO)
instead of on the server.

Who uses it: whoever runs the install. Site admins can read the status under **Site settings → File storage**.

- Set `SIGHTPRESS_S3_ACCESS_KEY` and `SIGHTPRESS_S3_SECRET_KEY`, then run
  `site:storage:s3 <site> --bucket= --region= [--endpoint=] [--prefix=] [--path-style]`.
- `site:storage:migrate <site> [--delete-source]` copies and checks every file, then switches the site.
- `site:purge` also removes the site's bucket folder and its default local folder.

Decision: ADR-0046.
