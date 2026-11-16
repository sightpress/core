# Media library: storage and uploads (C-5.1)

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

## Code and tests

- `src/Media/`, `src/Tenant/SiteStorage.php`, `src/Tenant/SiteContainerFactory.php`
- `tests/Unit/Media/`, `tests/Integration/Media/MediaUploadTest.php`
