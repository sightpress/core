<?php

declare(strict_types=1);

namespace SightPress\Core\Infrastructure\Crypto;

use RuntimeException;

/**
 * A value could not be encrypted or decrypted: `APP_SECRET` is missing, or the value was made with another secret
 * or changed. The message never contains the value.
 */
final class SecretBoxException extends RuntimeException
{
}
