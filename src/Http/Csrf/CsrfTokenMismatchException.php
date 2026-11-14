<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Csrf;

use Slim\Exception\HttpForbiddenException;

/**
 * 403: a form was sent without a valid CSRF token — it expired, or it came from another site.
 */
final class CsrfTokenMismatchException extends HttpForbiddenException
{
}
