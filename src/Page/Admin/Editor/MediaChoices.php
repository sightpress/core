<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Admin\Editor;

use SightPress\Core\Block\Contract\BlockSchema;
use SightPress\Core\Media\Contract\MediaFile;
use SightPress\Core\Media\Contract\MediaFormats;
use SightPress\Core\Media\Contract\MediaKind;
use SightPress\Core\Media\Contract\MediaLibrary;

/**
 * The files of the media library a media field can use, as choices for a select: pictures for an image, WebVTT
 * files for captions, and so on, newest first. Each choice names the file and its type, and says when the file
 * isn't published yet.
 */
final class MediaChoices
{
    /** @var array<string, array<int, string>> */
    private array $byFormat = [];

    public function __construct(
        private readonly MediaLibrary $library,
        private readonly MediaFormats $formats,
    ) {
    }

    /**
     * @param int|null $current the file chosen now, kept even when it isn't among the newest
     *
     * @return array<int, string> file id => label
     */
    public function for(string $format, ?int $current = null): array
    {
        if (!isset($this->byFormat[$format])) {
            [$kind, $mimeType] = match ($format) {
                BlockSchema::FORMAT_IMAGE => [MediaKind::Image, null],
                BlockSchema::FORMAT_VIDEO => [MediaKind::Video, null],
                BlockSchema::FORMAT_AUDIO => [MediaKind::Audio, null],
                BlockSchema::FORMAT_DOCUMENT => [MediaKind::Document, null],
                BlockSchema::FORMAT_CAPTIONS => [MediaKind::Document, 'text/vtt'],
                default => [null, null],
            };
            $this->byFormat[$format] = [];
            foreach ($this->library->choices($kind, $mimeType) as $file) {
                $this->byFormat[$format][(int) $file->id()] = $this->label($file);
            }
        }
        $choices = $this->byFormat[$format];
        if ($current !== null && !isset($choices[$current])) {
            $file = $this->library->file($current);
            $choices[$current] = $file === null ? sprintf('File %d (no longer in the media library)', $current) : $this->label($file);
        }

        return $choices;
    }

    private function label(MediaFile $file): string
    {
        $label = sprintf('%s (%s)', $file->originalName(), $this->formats->label($file->mimeType()) ?? $file->mimeType());

        return $file->isPublished() ? $label : $label . ', not published yet';
    }
}
