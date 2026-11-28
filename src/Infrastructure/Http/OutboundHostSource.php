<?php

declare(strict_types=1);

namespace SightPress\Core\Infrastructure\Http;

/**
 * Hosts of the operator's own services that a core feature calls but that aren't a third party the Privacy Policy
 * names, such as a storage server or a private AI server on the operator's network (GD-1). Implemented by core
 * services only; modules declare hosts with {@see \SightPress\Module\Privacy\ProcessorDeclaration}.
 */
interface OutboundHostSource
{
    /**
     * @return iterable<string> host names or `*.domain` patterns
     */
    public function outboundHosts(): iterable;
}
