<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Upload;

use RuntimeException;
use SightPress\Core\Template\Message;

/**
 * A file sent in parts can't go on. The message is written for the person uploading it; the status is the HTTP answer.
 */
final class UploadRefused extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly Message $text)
    {
        parent::__construct($text->text);
    }
}
