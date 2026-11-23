# Captions and transcripts (M-4)

## What it does

- **Captions:** on a video's page in the media library, upload a WebVTT file (`.vtt`) for each language. Visitors get a
  captions choice per language in the player. It starts on the page's language, or else the site's main language.
- **A file with a mistake** is refused with the line to fix, for example "line 3: the cue must end after it starts".
- **Transcripts:** write one per language for video and audio. Visitors open it with the "Transcript" button under the
  player, by mouse or keyboard.
- **Before publishing:** a video without captions, or a recording without a transcript, stops the page being published.
  A video with no speech needs a description in its transcript instead.
- **AI readers:** the transcripts of public files are in each page's `.md` version and in `llms-full.txt`.

## Tidy up a transcript with the AI assistant

With the assistant on, save a transcript of up to 6,000 characters and choose **Tidy up the transcript**. It adds
punctuation, paragraphs and speaker labels, and can't change the words: an answer that does is refused. Choose
**Insert**, read it against the recording, tick **I checked the transcript against the recording**, and save. Until
someone does, pages that show it can't be published.

## For developers

- `media.caption_tracks` and `media.transcript_suggested` (site migration `Version20261123150000`); ADR-0056.
- `WebVtt::check()` streams the file; `MediaFile::captionFiles()`, `MediaUrlGenerator::siteUrl()`.
- `POST /admin/media/{id}/captions`; publish check `media.transcript_unchecked`; assistant task `transcript_cleanup`.
- Personal data: captions and transcripts can name or quote people. They are deleted with the file.
