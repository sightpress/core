<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Error;

/**
 * An HTTP error after which the client may try again; the response gets a `Retry-After` header and the error page
 * says when.
 */
interface RetryAfter
{
    /**
     * Seconds until the client may try again, or null when unknown.
     *
     * @return positive-int|null
     */
    public function retryAfter(): ?int;
}
