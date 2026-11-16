<?php

declare(strict_types=1);

namespace SightPress\Core\Media;

use SightPress\Core\Media\Contract\MediaFile;
use SightPress\Core\Rbac\Contract\Actor;

/**
 * Who may open a file: anyone when it is published; otherwise only people who can manage media (`media.upload`),
 * so they can preview what they uploaded before it is published.
 */
final readonly class MediaAccess
{
    public const string MANAGE = 'media.upload';

    public function canView(Actor $actor, MediaFile $file): bool
    {
        return $file->isPublished() || $actor->has(self::MANAGE);
    }
}
