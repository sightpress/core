<?php

declare(strict_types=1);

namespace SightPress\Core\Branding;

use RuntimeException;

/**
 * A branding file that can't be used; the message says why and what would work, in plain words.
 */
final class BrandingException extends RuntimeException
{
}
