# CDN delivery

What it does: visitors get a site's public files from a content delivery network. Private files stay protected.

Who uses it: whoever runs the install. Site admins see the address under **Site settings → File storage**.

- `site:storage:cdn <site> [--url=https://cdn.example.org] [--remove]`, then `theme:publish-assets`.
- Set up the CDN to pull from the site, forward its host name, include the query string in the cache key, and not
  forward cookies.
- After unpublishing a file or making a site members-only, a copy on the CDN can last up to five minutes.

Decision: ADR-0051.
