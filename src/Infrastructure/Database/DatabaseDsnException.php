<?php

declare(strict_types=1);

namespace SightPress\Core\Infrastructure\Database;

use RuntimeException;

/**
 * A database DSN can't be used. The message says why, never what the DSN is, so it never shows credentials.
 */
final class DatabaseDsnException extends RuntimeException
{
}
