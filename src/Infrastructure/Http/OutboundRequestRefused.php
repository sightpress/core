<?php

declare(strict_types=1);

namespace SightPress\Core\Infrastructure\Http;

use Symfony\Component\HttpClient\Exception\TransportException;

/**
 * A request to a host that no third party the site uses declares (GD-1). It is a transport error, so code that
 * already handles an unreachable service handles it too; its message names the host only, never what was sent.
 */
final class OutboundRequestRefused extends TransportException
{
    public function __construct(public readonly string $host)
    {
        parent::__construct(sprintf(
            'The request to %s was refused: no service the site uses declares this address. Set up and turn on the feature that needs it, or ask whoever runs the site to allow it.',
            $host === '' ? 'an address without a host' : $host,
        ));
    }
}
