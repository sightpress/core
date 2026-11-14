<?php

declare(strict_types=1);

namespace SightPress\Core\Tenant;

use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;

/**
 * One site's container (ADR-0003): the site's own services, and the tenant's for everything else.
 *
 * Site services are built by their own PHP-DI container, which looks their dependencies up here, so a site service
 * can depend on tenant services and on other services of the same site, but never on another site's.
 */
final class SiteContainer implements ContainerInterface
{
    /** @var array<string, true> */
    private array $ids;

    private readonly ContainerInterface $site;

    /**
     * @param array<string, mixed> $definitions PHP-DI definitions of the site's services
     */
    public function __construct(private readonly ContainerInterface $tenant, array $definitions)
    {
        $this->ids = array_fill_keys(array_keys($definitions), true);
        $this->site = new ContainerBuilder()
            ->useAutowiring(true)
            ->useAttributes(false)
            ->wrapContainer($this)
            ->addDefinitions($definitions)
            ->build();
    }

    public function get(string $id): mixed
    {
        return isset($this->ids[$id]) ? $this->site->get($id) : $this->tenant->get($id);
    }

    public function has(string $id): bool
    {
        return isset($this->ids[$id]) || $this->tenant->has($id);
    }
}
