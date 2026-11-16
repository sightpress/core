# The media library in the admin (C-5.3)

Who: contributors, editors and admins (`media.upload`). Publishing, unpublishing and deleting any file need
`media.publish` (editors and admins); people who uploaded a file may delete it while it is unpublished.

## Uploading

Choose **Media** in the admin navigation (`/admin/media`). Under **Upload a file**, choose the file (the file
picker lists the types that can be uploaded) and **Upload**.

- While the file is sent, a progress bar fills up, and screen readers hear "Uploading dusk.png: 25%", then 50, 75
  and 100%, then "dusk.png has arrived. Checking the file…". The **Upload** button is unavailable meanwhile.
- Without JavaScript the form is sent the usual way, without the progress bar.
- When the file is accepted you go to its page, with "dusk.png has been uploaded. Describe it below, so everyone can
  understand it." read out. A file that is already in the library isn't added twice; you go to the one that is.
- When the file is refused, the reason is at the top of the page and gets focus (for example "This type of file
  can't be uploaded…" or "The file is 23 MB; pictures can be at most 20 MB.").

New files are **not published**: only people who can upload media can see them until an editor publishes them.

## The files

Files are shown newest first, 48 per page, as a **Grid** of cards (thumbnail, name, type and size, whether it is
published, and "alt text missing" for pictures without alt text) or as a **List** (a table with the name, type,
size, alt text, status and upload date). The links **Grid** and **List** switch views; the current one is marked,
and the choice is remembered until you sign out.

## One file

Choose a file's name to open its page: a preview (pictures with their alt text, video and audio with players), its
type, size, dimensions, status, upload date and address, and **Details**:

- **Alt text** (pictures only): what the picture shows and why it is there, as you would say it to someone on the
  phone. Pages can still mark a picture as decorative where it adds nothing.
- **Caption**: shown under the file on pages that show captions.
- With more than one site language, there is an alt text and a caption for each language ("Alt text in Portuguese
  (Brazil)").
- **Transcript** (video and audio only): everything that is said, and other sounds that matter.
- **Published** (editors and admins only).

**Save details** saves and says so. **Delete this file** asks first; deleting removes the file and its smaller
copies for good, and pages that use it show it as missing.

## For developers

- Module `media_admin` (`src/Media/Admin`), always on, separate from `media` because the admin shell depends on
  pages → blocks → media.
- Routes `media_admin.library` (`GET /admin/media`), `media_admin.upload` (`POST /admin/media/upload`),
  `media_admin.item` (`/admin/media/{id}`), `media_admin.delete` (`/admin/media/{id}/delete`).
- `js/media-upload.js` sends the form with `X-Requested-With: XMLHttpRequest`; the upload handler then answers
  `{"location": "…"}` instead of a 303, and keeps the flash message or the refusal in the session for that page.
- `Media\MediaEditor` saves descriptions and deletes; events `Media\Contract\MediaChanged` (fields `alt`, `caption`,
  `transcript`, `published`) and `Media\Contract\MediaDeleted` for the audit log.
- Tests: `tests/Unit/Media/Admin`, `tests/Unit/Media/MediaEditorTest.php`,
  `tests/Integration/Media/MediaLibraryFlowTest.php`, `tests/A11y/media.spec.ts`.
