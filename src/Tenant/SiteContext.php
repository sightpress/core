<?php

declare(strict_types=1);

namespace SightPress\Core\Tenant;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The site a request belongs to, with its site container (ADR-0003). The site resolver puts it on the request;
 * handlers read it with {@see fromRequest()}.
 */
final readonly class SiteContext
{
    public function __construct(
        public Site $site,
        public ContainerInterface $container,
    ) {
    }

    public static function fromRequest(ServerRequestInterface $request): ?self
    {
        $context = $request->getAttribute(self::class);

        return $context instanceof self ? $context : null;
    }
}
