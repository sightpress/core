<?php

declare(strict_types=1);

namespace SightPress\Core\Tenant;

use RuntimeException;

/**
 * The site registry (`config/sites.php`, ADR-0002) is invalid, outdated or can't be written. The message names the
 * file, the site and the key, never a value, so it can be logged safely.
 */
final class SiteRegistryException extends RuntimeException
{
}
