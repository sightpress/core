<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Contract;

/**
 * What a media item is: shown inline (pictures, video, audio) or offered as a download (documents).
 */
enum MediaKind: string
{
    case Image = 'image';
    case Video = 'video';
    case Audio = 'audio';
    case Document = 'document';

    /**
     * Whether browsers show it in the page rather than download it.
     */
    public function isInline(): bool
    {
        return $this !== self::Document;
    }

    /**
     * Whether it can have a transcript (spoken content).
     */
    public function hasSpeech(): bool
    {
        return $this === self::Video || $this === self::Audio;
    }
}
