# Cloud storage

What it does: a site can keep its files in an S3-compatible bucket (AWS S3, Cloudflare R2, DigitalOcean Spaces, MinIO)
instead of on the server.

Who uses it: whoever runs the install. Site admins can read the status under **Site settings → File storage**.

- Set `SIGHTPRESS_S3_ACCESS_KEY` and `SIGHTPRESS_S3_SECRET_KEY`, then run
  `site:storage:s3 <site> --bucket= --region= [--endpoint=] [--prefix=] [--path-style]`.
- `site:storage:migrate <site> [--delete-source]` copies and checks every file, then switches the site.
- `site:purge` also removes the site's bucket folder and its default local folder.

## Setting up a bucket and CDN

- Set the provider's keys for this run: `SIGHTPRESS_AWS_ACCESS_KEY_ID` + `SIGHTPRESS_AWS_SECRET_ACCESS_KEY`, or
  `SIGHTPRESS_CLOUDFLARE_ACCOUNT_ID` + `SIGHTPRESS_CLOUDFLARE_API_TOKEN` (+ `SIGHTPRESS_CLOUDFLARE_ZONE_ID` for
  `--domain`). They are never saved.
- `site:storage:provision <site> --provider=aws|cloudflare [--region=] [--bucket=] [--domain=] --dry-run` prints the
  plan and contacts nobody. Run it again without `--dry-run` to create everything. Running it twice is safe.
- The site doesn't change until you connect the bucket with `site:storage:s3`, move files with `site:storage:migrate`
  and, once the CDN is deployed, turn it on with `site:storage:cdn`.
- To let site admins do this from **Site settings → File storage**, set `SIGHTPRESS_PROVISION_FROM_ADMIN=1` and the
  keys in the web server's environment. They can then create resources that cost money in that account.

Decision: ADR-0046, ADR-0053.
