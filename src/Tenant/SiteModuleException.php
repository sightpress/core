<?php

declare(strict_types=1);

namespace SightPress\Core\Tenant;

use RuntimeException;
use SightPress\Core\Template\TranslatableText;

/**
 * A change to a site's optional modules that can't be made; the message says why and names the modules involved.
 */
final class SiteModuleException extends RuntimeException
{
    use TranslatableText;
}
