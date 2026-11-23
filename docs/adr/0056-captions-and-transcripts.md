# 0056 — Captions and transcripts

- Status: Proposed
- Date: 2026-11-23

## Context

A video block could hold one captions file, and a WebVTT file was only checked on its first line. Sites in several
languages need captions per language, a broken file should be refused with something a person can fix, and the AI
assistant should be able to tidy a raw transcript without being able to change what was said.

## Decision

- **Captions belong to the video in the library,** one WebVTT file per language (`media.caption_tracks`). A block's own
  captions file still wins. Otherwise a page uses the file in its language, or else the main language's.
- **Every upload path checks the whole file,** as a stream, and names the first wrong line.
- **A `<track>` always loads from the site's own address,** never the CDN: browsers refuse a cross-origin track without
  CORS, and the failure is silent.
- **Publishing a video publishes its captions** for someone who may publish, so a public video never has hidden captions.
- **`transcript_cleanup` can't change the words.** Its answer is refused unless it has the same words in the same order.
  Only a label at the start of a line may be added, and it must be "Speaker n" or use words of the transcript.
- **A person accepts it.** A tidied transcript stays marked, and blocks publishing pages that show it, until someone
  ticks that they checked it against the recording or changes it.

## Consequences

The assistant tidies transcripts of up to 6,000 characters, about five minutes of speech, because the answer must arrive
within the 30 seconds an AI service is given. Longer transcripts need streaming or splitting, which is left for later.
The word check can't tell whether a speaker label is on the right line, which is why a person must check the result.
`llms.txt` stays an index of links; transcripts are in each page's `.md` version and in `llms-full.txt`.
