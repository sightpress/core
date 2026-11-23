<?php

declare(strict_types=1);

namespace SightPress\Core\Infrastructure\Storage;

use RuntimeException;

/**
 * A storage problem described in plain language. Its message is safe to show: it never holds a key.
 */
final class StorageException extends RuntimeException
{
}
