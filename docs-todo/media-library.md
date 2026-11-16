# Media library: storage, uploads and delivery (C-5.1, C-5.2)

## What it does

Every site has its own media library: pictures, video, audio and documents that pages can use. Each site keeps
its files in its own folder, and the library checks every upload before it is stored:

- **Real type.** The type is read from the file's content, never from its name. Only the types below are
  accepted; anything else (SVG, HTML, scripts, programs) is refused with a sentence listing what can be uploaded.
- **Size limits.** Pictures up to 20 MB and 25 million pixels, video up to 1 GB, audio up to 200 MB, documents up
  to 50 MB. The message says how big the file is and what the limit is ("The file is 23 MB; pictures can be at
  most 20 MB.").
- **Pictures are cleaned.** They are turned the right way up, then re-encoded, which removes all metadata: the
  camera, the GPS position where the photo was taken, comments, and anything hidden after the image. Smaller
  copies are made at 320, 640, 960, 1280 and 1920 pixels wide (only those narrower than the picture), so pages can
  send phones a small file.
- **Random names.** Stored files get a random 32-character name, so nothing about a file can be guessed from its
  address. The name it had on the uploader's computer is kept to show people, and a clean version of it
  (`relatorio-anual-2026.pdf`) is used in addresses and downloads.
- **Stored once.** Uploading the same file again gives back the entry that is already there.
- **Not published yet.** New files are private until someone with the "Publish media" permission publishes them.

Alt text and captions are kept per language; video and audio can have a transcript.

### Addresses and delivery (C-5.2)

Each site serves its own files:

- **Addresses** look like `/media/<key>/lighthouse.jpg`; the smaller copies of a picture are at
  `/media/<key>/640w/lighthouse.jpg`. Pages list all widths in `srcset` so browsers pick the smallest that fits.
  An old or mistyped file name after the key redirects to the right address.
- **Who can open a file.** Published files are open to everyone. Unpublished files are only shown to signed-in
  people who can upload media (to preview them); anyone else gets "page not found", so nobody learns the file
  exists.
- **Shown or downloaded.** Pictures, video and audio open in the browser; documents are downloaded under the name
  they had when uploaded (accents kept).
- **Fast and light.** A file's address never changes its content, so browsers and caches keep published files for
  a year and ask again only with a check (`ETag`, `Last-Modified` → "not modified"). Video and audio players can
  jump to any point without downloading the whole file (byte ranges). Unpublished files are never cached.
- **Safe when opened directly.** Files are sent with a policy that lets them run and load nothing, and the browser
  is told not to guess their type.

Unpublishing a file stops it being served, but a visitor's browser (or a cache in between) may keep a copy it
already downloaded until it expires. To withdraw something sensitive, delete it (C-5.3) rather than only
unpublish it.

| Kind | Types |
| --- | --- |
| Pictures | JPEG, PNG, WebP, GIF |
| Video | MP4, WebM |
| Audio | MP3, M4A, Ogg, WAV |
| Documents | PDF, Word, Excel, PowerPoint, OpenDocument text / spreadsheet / presentation, plain text, CSV, ZIP |

## Who uses it

- **Contributors, editors and admins** upload files (`media.upload`); **editors and admins** publish them
  (`media.publish`). The upload screen comes with the admin media library (C-5.3); this task adds the service it
  uses.
- **Operators**: files live in `sites/<site id>/media/` of the project (or in the site's `storage.path` from the
  site registry). Back this folder up with the site's database. The PHP extensions `gd` and `exif` are required;
  pictures are decoded in memory, so PHP's `memory_limit` should be at least 256M.

## For developers

- Module `media` (`src/Media/MediaModule.php`), always on. Table `media` (one row per file).
- `MediaUploader::upload($path, $clientName, $userId)` and `uploadFile(UploadedFileInterface, $userId)` return an
  `UploadResult` (`media`, `duplicate`); refusals throw `MediaException` with a message for the uploader.
- `MediaUploaded` (in `Media\Contract`) is dispatched for each new file.
- Other modules see files through `Media\Contract\MediaFile`.
- Each site's container has a `League\Flysystem\FilesystemOperator` for the site's storage; the folder is created
  when the first file is written.
- `Media\Contract\MediaUrlGenerator` (public service) gives `url($file, ?$width)` and `srcset($file)`. In origin
  mode (`OriginMediaUrlGenerator`) addresses are root-relative; prefix them with the site's base URL where an
  absolute URL is needed (Open Graph). A CDN mode can replace it later without changing callers.
- Routes `media.file` (`/media/{key}/{name}`) and `media.variant` (`/media/{key}/{width}w/{name}`), GET and HEAD,
  handled by `Media\Http\MediaFileHandler`. Access rule: `MediaAccess` (published, or the actor has
  `media.upload`). Only single byte ranges are served; a list of ranges gets the whole file (RFC 9110 allows it).
- A row whose stored file is missing answers 404 and logs an error with the key and width.

## Code and tests

- `src/Media/` (delivery in `src/Media/Http/`), `src/Tenant/SiteStorage.php`, `src/Tenant/SiteContainerFactory.php`
- `tests/Unit/Media/`, `tests/Integration/Media/MediaUploadTest.php`, `tests/Integration/Media/MediaDeliveryTest.php`
