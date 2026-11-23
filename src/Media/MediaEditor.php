<?php

declare(strict_types=1);

namespace SightPress\Core\Media;

use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use SightPress\Core\Media\Contract\MediaChanged;
use SightPress\Core\Media\Contract\MediaDeleted;
use SightPress\Core\Media\Entity\Media;

/**
 * Changes and deletes files of the media library, and says so with {@see MediaChanged} and {@see MediaDeleted}.
 */
final readonly class MediaEditor
{
    public function __construct(
        private MediaRepository $media,
        private MediaFiles $files,
        private ClockInterface $clock,
        private EventDispatcherInterface $events,
    ) {
    }

    /**
     * Saves the descriptions. Only what differs from what is stored counts as a change; nothing is saved when
     * nothing changed.
     *
     * @param array<string, array{alt: ?string, caption: ?string, alt_suggested?: ?bool}> $descriptions by locale;
     *                                                                     `alt_suggested` true when the alt text is the
     *                                                                     AI assistant's and nobody checked it, false
     *                                                                     when a person checked it, null (or left out)
     *                                                                     to keep the mark unless the text changed
     * @param array<string, ?string>|null                          $transcripts  by locale, only for video and audio;
     *                                                                           null leaves them
     * @param ?bool                                                $published    null leaves it
     * @param ?string                                              $mainLocale   the site's main language, whose
     *                                                                           transcript replaces one written before
     *                                                                           files had one per language
     *
     * @param array<string, ?bool>                                 $transcriptSuggested by locale: true when the
     *                                                                           transcript is the AI assistant's and nobody
     *                                                                           checked it, false when a person did, null (or
     *                                                                           left out) to keep the mark unless it changed
     *
     * @return list<string> what changed (see {@see MediaChanged::$fields})
     *
     * @throws MediaException when a text is too long, a locale is not valid, or a transcript is given for a picture
     */
    public function update(Media $media, array $descriptions, ?array $transcripts, ?bool $published, ?int $changedBy, ?string $mainLocale = null, array $transcriptSuggested = []): array
    {
        $now = $this->clock->now();
        $changed = [];
        foreach ($descriptions as $locale => $texts) {
            $alt = $media->altText($locale);
            $caption = $media->caption($locale);
            $suggested = $media->isAltSuggested($locale);
            $media->describe($locale, $texts['alt'], $texts['caption'], $now);
            $media->markAltSuggested($locale, $texts['alt_suggested'] ?? ($suggested && $alt === $media->altText($locale)));
            $changed['alt'] = ($changed['alt'] ?? false) || $alt !== $media->altText($locale) || $suggested !== $media->isAltSuggested($locale);
            $changed['caption'] = ($changed['caption'] ?? false) || $caption !== $media->caption($locale);
        }
        foreach ($transcripts ?? [] as $locale => $transcript) {
            $before = $media->transcript($locale);
            $wasSuggested = $media->isTranscriptSuggested($locale);
            $media->setTranscript($locale, $transcript, $now, $locale === $mainLocale);
            $media->markTranscriptSuggested($locale, $transcriptSuggested[$locale] ?? ($wasSuggested && $before === $media->transcript($locale)));
            $changed['transcript'] = ($changed['transcript'] ?? false) || $before !== $media->transcript($locale) || $wasSuggested !== $media->isTranscriptSuggested($locale);
        }
        if ($published !== null && $published !== $media->isPublished()) {
            $published ? $media->publish($now) : $media->unpublish($now);
            $changed['published'] = true;
            if ($published) {
                $this->publishCaptions($media, $changedBy);
            }
        }

        $fields = array_keys(array_filter($changed));
        if ($fields !== []) {
            $this->media->save($media);
            $this->events->dispatch(new MediaChanged((int) $media->id(), $changedBy, $fields));
        }

        return $fields;
    }

    /**
     * Sets or removes (`$file` null) a video's captions file in one language. The captions of a published video are
     * published with it when `$canPublish`, so visitors don't get a video whose captions stay hidden.
     *
     * @throws MediaException when `$file` isn't a WebVTT file or `$media` isn't a video
     */
    public function setCaptions(Media $media, string $locale, ?Media $file, ?int $changedBy, bool $canPublish): void
    {
        if ($file !== null && $file->mimeType() !== 'text/vtt') {
            throw new MediaException('Choose a captions file in WebVTT format (.vtt).');
        }
        $media->setCaptionFile($locale, $file?->id(), $this->clock->now());
        $this->media->save($media);
        $this->events->dispatch(new MediaChanged((int) $media->id(), $changedBy, ['captions']));
        if ($file !== null && $canPublish && $media->isPublished() && !$file->isPublished()) {
            $file->publish($this->clock->now());
            $this->media->save($file);
            $this->events->dispatch(new MediaChanged((int) $file->id(), $changedBy, ['published']));
        }
    }

    private function publishCaptions(Media $video, ?int $changedBy): void
    {
        foreach ($this->media->files(array_values($video->captionFiles())) as $file) {
            if (!$file->isPublished()) {
                $file->publish($this->clock->now());
                $this->media->save($file);
                $this->events->dispatch(new MediaChanged((int) $file->id(), $changedBy, ['published']));
            }
        }
    }

    /**
     * Removes the file, its smaller copies and its row, and takes it off the videos that used it as captions.
     */
    public function delete(Media $media, ?int $deletedBy): void
    {
        $id = (int) $media->id();
        foreach ($this->media->videosCaptionedBy($id) as $video) {
            foreach (array_keys($video->captionFiles(), $id, true) as $locale) {
                $video->setCaptionFile((string) $locale, null, $this->clock->now());
            }
            $this->media->save($video);
        }
        $this->files->delete($media);
        $this->media->remove($media);
        $this->events->dispatch(new MediaDeleted($id, $media->originalName(), $deletedBy));
    }
}
