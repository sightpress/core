<?php

declare(strict_types=1);

namespace SightPress\Core\Infrastructure\Storage\Provisioning;

use RuntimeException;

/**
 * Provisioning failed. The message is plain language and safe to show: it never holds a key or a provider's own text.
 */
final class ProvisioningException extends RuntimeException
{
}
