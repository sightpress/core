<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Error;

use Slim\Exception\HttpForbiddenException;

/**
 * 403 for a signed-in person whose role doesn't include the admin panel (for example a `member`). The page links to
 * the site and to *My account* instead.
 */
final class NoAdminAccessException extends HttpForbiddenException
{
}
