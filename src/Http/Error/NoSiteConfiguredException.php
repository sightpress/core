<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Error;

use Slim\Exception\HttpNotFoundException;

/**
 * 404 for a request that no site answers: shown as the "No site configured" page.
 */
final class NoSiteConfiguredException extends HttpNotFoundException
{
}
