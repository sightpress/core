# Upload limits (M-5, first part)

How large an upload can be depends on two things: the site's own limits for each kind of file (`MediaLimits`:
pictures 20 MB, documents 50 MB, audio 200 MB, video 1 GB) and what the server lets one request carry. SightPress
works out the smaller of the two, shows it next to the upload field, and warns admins when the server's settings
stop uploads from working. Plan §1.2 *Uploads & server limits*, D41, D42.

## For people who upload (contributors, editors, admins)

- The media library's upload field says how large each kind of file can be: "Largest file you can upload: pictures
  20 MB, documents 50 MB, audio files 64 MB and videos 64 MB."
- A file that is too large is refused in the browser before it is sent. The reason appears under the field, linked
  to it (`aria-describedby`, `aria-invalid`), and the field gets focus. Choosing another file removes it. Without
  JavaScript the server refuses the file and explains why in the error summary, as before.
- When a request is larger than the server accepts, the person sees a page called **This is too large to send**
  (status 413) that says how much the server accepts and links back to the form. This replaces the confusing
  "This form has expired" page (PHP drops the whole request, CSRF token included) and nginx's own error page.

## For admins (`settings.manage`)

- The dashboard and the media library show **Your server’s settings stop some uploads** when something must be
  fixed. Each point names the setting and the value it needs, for example "Ask your hosting provider to raise
  upload_max_filesize to 50M and post_max_size to 51M". It links to the how-to *Fix upload size limits*.
- What is checked (`ServerLimitsCheck`): one request can't carry the largest picture or document the site accepts;
  `post_max_size` is smaller than `upload_max_filesize`; the web server's limit is below PHP's; `memory_limit` is too
  low for the picture pixel limit (about 10 bytes a pixel: 256M for 25 million pixels); `max_input_vars` is below
  5000 (a long page in the block editor would lose fields); the folder for uploads is missing, not writable or has
  less free space than one request.
- Audio and video above the server's limit are not a problem yet, only a note: larger files will be sent in parts
  (chunked uploads, M-7 / M-8).
- Editors and contributors only see the largest file they can upload.

## For operators

- `bin/sightpress system:check` lists the limits, the largest file of each kind, notes and problems; exit code 1
  when something must be fixed. In the skeleton run it in the PHP container
  (`docker compose exec php bin/sightpress system:check`): the command line can have its own `php.ini`.
- `config/tenant.php` → `uploads.proxy_body_limit`: the web server's request body limit (`65M`, `1G` or bytes),
  which PHP can't read. The skeleton works it out from `.env`.
- The skeleton sets PHP and nginx from one `.env` block (`UPLOAD_MAX_SIZE`, `INPUT_MAX_SIZE`, `INPUT_MAX_VARS`,
  `MAX_FILE_UPLOADS`, `PHP_MEMORY_LIMIT`, `UPLOAD_TIMEOUT`), with the recommended *standard* values. Its nginx sends
  a refused request (`error_page 413`) to `/_sightpress/request-too-large`, which shows the same page as above.

## Not yet (rest of M-5, M3)

Per-site limits (`site:media:limits`, `tenant.media.limits` and its ceiling), the effective limit on the avatar and
branding forms, hiding the admin notice until the result changes, counting requests the web server refused,
`system:check --site= --probe-url= --recommend`.

## Code and tests

- `src/Http/Upload/` (`IniSize`, `ServerLimits`, `ServerLimitsCheck`, `RequestOverflowMiddleware`,
  `RequestTooLargeException`, `RequestTooLargeHandler`), `src/Media/UploadAllowance.php`,
  `src/Media/Command/SystemCheckCommand.php`, `resources/views/admin/server-problems.html.twig`, the size check in
  `assets/js/site.js` of the default theme (`sightpress/theme-default`).
- Unit tests in `tests/Unit/Http/Upload/` and `tests/Unit/Media/Command/`; the kernel test in
  `tests/Integration/Http/SecurityPipelineTest.php`; the browser check in `tests/A11y/media.spec.ts`.
