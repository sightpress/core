<?php

declare(strict_types=1);

namespace SightPress\Core\User;

/**
 * Where a user's avatar comes from.
 */
enum AvatarKind: string
{
    /** Initials on a coloured background, made by SightPress and remade when the name changes. */
    case Generated = 'generated';

    /** A picture the person uploaded. */
    case Uploaded = 'uploaded';
}
