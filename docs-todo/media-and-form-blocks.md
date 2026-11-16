# Blocks: gallery, video, audio, file download and contact form (C-4.3)

## What it does

Five more block types, on top of the ones in [blocks](blocks.md).

| Block | What it holds | Accessibility built in |
| --- | --- | --- |
| **Gallery** | Up to 50 pictures from the media library, each with alt text (or "decorative") and a caption, and a caption for the whole gallery. | Shown as a list, so screen readers say how many pictures there are. Every picture is checked like an Image block. |
| **Video** | A video, a title, a captions file (WebVTT, `.vtt`), the language of the captions, an optional poster picture and a transcript. | Captions are needed before publishing, unless "This video has no speech" is ticked; then the transcript must describe what the video shows. A transcript is recommended for every video. The video never starts by itself. |
| **Audio** | An audio file, a title and a transcript. | The transcript is needed before publishing. The audio never starts by itself. |
| **File download** | A file from the media library and the link text. | The link says what the file is, then its type and size: "Annual report (PDF, 2.3 MB)". Vague link text gets a warning. |
| **Contact form** | The address messages go to, and optionally the email subject, the button text and the thank-you message. | Labelled fields (name, email, message), an error summary that gets focus, a thank-you message that gets focus. |

- **Transcripts** are shown under the player behind a "Transcript" button that opens and closes them.
- **Captions files** are uploaded to the media library like any other file. Only WebVTT files are accepted: the
  first line must be `WEBVTT`.
- **File sizes** are written in the page's language: "2.3 MB" in English, "2,3 MB" in Portuguese.
- **The contact form** never shows the address it sends to. Messages arrive from the site's own address with the
  visitor as the reply-to address, so you answer by replying. Spam is kept out without a puzzle: a hidden field
  people never see (bots fill it in and are ignored) and at most 5 messages per visitor every 10 minutes. After
  sending, the visitor comes back to the same page, which shows either what to fix (with what they typed kept) or
  the thank-you message. A form only works on a published, public page.

## For developers

- Block types `gallery`, `video`, `audio`, `file_download` (module `block`) and `contact_form` (new required module
  `contact`, needs `block` + `page`; route `POST /contact-form`, rate limit policy `contact_form`).
- New schema formats: `media-captions` (a WebVTT file). The validator checks the kind (and type) of every file a
  `media-*` field points at.
- `BlockContexts::create($locale, $baseUrl, $request)`: pass the visitor's request when rendering a page for them,
  so the contact form has its CSRF token and the last submission's outcome. A page rendered with a request is
  personal: the public page router (C-6.2) must not serve it from a shared cache.
- New contracts: `Block\Contract\HttpBlockContext`, `Block\Contract\BlockFinder` (blocks of one type, also inside
  columns), `Page\Contract\PublicPages` (a published, public page by its address), `Media\Contract\MediaFormats`
  (names of file types).

## Still to write in the docs

- Reference: the fields and limits of each block; what the contact email looks like.
- How-to (non-developers): add captions to a video (make a `.vtt` file, upload it, choose it); write a transcript;
  add a contact form and test it.
