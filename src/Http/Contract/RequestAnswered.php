<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Contract;

use DateTimeImmutable;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Dispatched on the site's event dispatcher for each request to the site, **after the response has been sent**
 * (ADR-0037), so listeners such as analytics can't slow the response down or change it. A listener that throws is
 * logged and never shown to anyone.
 *
 * Listeners get the request as the handler saw it, the status sent (404 for "not found", also when it was thrown) and
 * what the handler said it showed ({@see AnsweredResource}). They must not keep the IP address, the full user agent
 * or the query string unless they really need to and say so (ADR-0012).
 */
final readonly class RequestAnswered
{
    public function __construct(
        public ServerRequestInterface $request,
        public int $status,
        public AnsweredResource $resource,
        public bool $signedIn,
        public DateTimeImmutable $at,
    ) {
    }
}
