<?php

declare(strict_types=1);

namespace SightPress\Core\Tenant;

use RuntimeException;

/**
 * The tenant configuration (`config/tenant.php` or the environment) is missing or invalid. The message names the
 * setting and says how to fix it.
 */
final class TenantConfigException extends RuntimeException
{
}
