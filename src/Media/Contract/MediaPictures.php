<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Contract;

use SightPress\Core\Media\Entity\Media;

/**
 * The pictures of the media library as files, for modules that look at them (the AI assistant's alt text), and the
 * alt text the AI assistant wrote that no person has checked yet.
 */
interface MediaPictures
{
    public const int ALT_MAX = Media::ALT_MAX_LENGTH;

    /** Who wrote an alt text that still needs a person to check it. */
    public const string SUGGESTED_BY_ASSISTANT = 'assistant';

    /**
     * The picture's bytes in a copy at most `$width` pixels wide (or the original when it is smaller), in a format
     * an AI service reads (JPEG, PNG, GIF, WebP); null for other files and missing ones.
     *
     * @return array{type: string, data: string}|null
     */
    public function picture(int $id, int $width): ?array;
}
