<?php

declare(strict_types=1);

namespace SightPress\Core\Media;

use SightPress\Core\Template\TranslatableText;
use RuntimeException;

/**
 * A file can't be added to the media library, or a description is not valid. The message is written for the person
 * who uploaded it.
 */
final class MediaException extends RuntimeException
{
    use TranslatableText;
}
