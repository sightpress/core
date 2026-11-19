<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant\Http;

use RuntimeException;
use SightPress\Core\Assistant\AssistantError;
use SightPress\Core\Template\Message;

/**
 * The assistant may not send a request to an address ({@see EndpointGuard}); {@see $reason} says why, in plain words
 * (the exception message is the same in en-GB).
 */
final class EndpointRefused extends RuntimeException
{
    public function __construct(public readonly Message $reason)
    {
        parent::__construct(AssistantError::english($reason));
    }
}
