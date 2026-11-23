# Chunked uploads

What it does: large files are sent to the media library in parts, so a web server limit no longer decides how large a
file can be. An upload can be paused, resumed, cancelled, and picked up again after a lost connection or a reload.

Who uses it: anyone who uploads media; whoever runs the install sets the folder and the largest size.

- `uploads.temp_path` (default `var/uploads`, never under `public/`) and `uploads.max_chunked_bytes` (default 5 GB) in
  `config/tenant.php`.
- `site:media:uploads <site> [--purge-expired]`; `schedule:run` removes abandoned uploads.
- Several web servers must share the upload folder.

How-to: `docs/how-to/upload-large-files.md`. Decision: ADR-0054.
