<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Http;

use RuntimeException;

/**
 * The requested byte range lies outside the file: answered with 416.
 */
final class UnsatisfiableRange extends RuntimeException
{
}
