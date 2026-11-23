# Upload limits per site

What it does: each site can accept larger or smaller files than the others, up to a ceiling for the whole install.
Admins see the result, can hide the server warning until it changes, and are told about uploads the web server refused.

Who uses it: whoever runs the install (`site:media:limits`, `system:check`); site admins read the limits in **Site
settings** and on the avatar and branding forms.

- `site:media:limits <site> [--image=] [--video=] [--audio=] [--document=] [--pixels=] [--reset]`.
- `media.limits` and `media.limits.max` in `config/tenant.php`; a site's override is in `config/sites.php`.
- `system:check --site= --probe-url= --recommend`.
- After updating: `site:registry:upgrade` (registry format 2).

How-to: `docs/how-to/choose-upload-limits.md`. Decision: ADR-0050.
