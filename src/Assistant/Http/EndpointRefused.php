<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant\Http;

use RuntimeException;

/**
 * The assistant may not send a request to an address ({@see EndpointGuard}); the message says why, in plain words.
 */
final class EndpointRefused extends RuntimeException
{
}
