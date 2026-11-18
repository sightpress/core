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
     * @param array<string, array{alt: ?string, caption: ?string}> $descriptions by locale
     * @param array<string, ?string>|null                          $transcripts  by locale, only for video and audio;
     *                                                                           null leaves them
     * @param ?bool                                                $published    null leaves it
     * @param ?string                                              $mainLocale   the site's main language, whose
     *                                                                           transcript replaces one written before
     *                                                                           files had one per language
     *
     * @return list<string> what changed (see {@see MediaChanged::$fields})
     *
     * @throws MediaException when a text is too long, a locale is not valid, or a transcript is given for a picture
     */
    public function update(Media $media, array $descriptions, ?array $transcripts, ?bool $published, ?int $changedBy, ?string $mainLocale = null): array
    {
        $now = $this->clock->now();
        $changed = [];
        foreach ($descriptions as $locale => $texts) {
            $alt = $media->altText($locale);
            $caption = $media->caption($locale);
            $media->describe($locale, $texts['alt'], $texts['caption'], $now);
            $changed['alt'] = ($changed['alt'] ?? false) || $alt !== $media->altText($locale);
            $changed['caption'] = ($changed['caption'] ?? false) || $caption !== $media->caption($locale);
        }
        foreach ($transcripts ?? [] as $locale => $transcript) {
            $before = $media->transcript($locale);
            $media->setTranscript($locale, $transcript, $now, $locale === $mainLocale);
            $changed['transcript'] = ($changed['transcript'] ?? false) || $before !== $media->transcript($locale);
        }
        if ($published !== null && $published !== $media->isPublished()) {
            $published ? $media->publish($now) : $media->unpublish($now);
            $changed['published'] = true;
        }

        $fields = array_keys(array_filter($changed));
        if ($fields !== []) {
            $this->media->save($media);
            $this->events->dispatch(new MediaChanged((int) $media->id(), $changedBy, $fields));
        }

        return $fields;
    }

    /**
     * Removes the file, its smaller copies and its row.
     */
    public function delete(Media $media, ?int $deletedBy): void
    {
        $id = (int) $media->id();
        $this->files->delete($media);
        $this->media->remove($media);
        $this->events->dispatch(new MediaDeleted($id, $media->originalName(), $deletedBy));
    }
}
