<?php

declare(strict_types=1);

namespace SightPress\Core\Block\Contract;

use Psr\Http\Message\ServerRequestInterface;
use SightPress\Module\Block\BlockContext;

/**
 * The context core renders blocks with on a site. Core modules whose blocks hold a form (contact form) read the
 * visitor's request from it, for the CSRF token and what the last submission left in the session. A page rendered
 * with a request is personal: it must not be shared from a cache.
 */
interface HttpBlockContext extends BlockContext
{
    public function request(): ?ServerRequestInterface;
}
