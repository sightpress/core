<?php

declare(strict_types=1);

namespace SightPress\Core\User\Avatar;

use RuntimeException;

/**
 * A picture that can't be used as an avatar; the message says why, in plain words for the person uploading it.
 */
final class AvatarException extends RuntimeException
{
}
