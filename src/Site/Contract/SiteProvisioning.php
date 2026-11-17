<?php

declare(strict_types=1);

namespace SightPress\Core\Site\Contract;

use Psr\Container\ContainerInterface;
use SightPress\Core\Tenant\Site;

/**
 * Dispatched by `site:create` once the new site's database is migrated, before the site is added to the registry.
 * Each module that sets something up on a new site listens to it (`subscribers()->listen(SiteProvisioning::class,
 * …, priority)`); listeners run from the highest priority down:
 *
 * - {@see self::ACCOUNTS} (300): the first admin's account
 * - {@see self::ROLES} (200): roles and permissions, and the admin role for the first admin
 * - {@see self::SIGN_IN} (100): the link the first admin uses to choose a password
 * - {@see self::CONTENT} (0) and lower: starter pages, branding and anything else
 *
 * A listener takes the services it needs from {@see self::$services} (the new site's container, against its own
 * database) and tells the operator what it did with {@see report()}. If a listener throws, the site is not added to
 * the registry.
 */
final class SiteProvisioning
{
    public const int ACCOUNTS = 300;
    public const int ROLES = 200;
    public const int SIGN_IN = 100;
    public const int CONTENT = 0;

    /**
     * Demo content (`site:create --with-demo-content`), after everything else, in this order: media files (-100),
     * accounts for each role (-110), a page with every block type (-120), menu entries (-130).
     */
    public const int DEMO = -100;

    private ?int $adminId = null;

    /** @var list<string> */
    private array $lines = [];

    /** @var array<string, int> */
    private array $shared = [];

    /**
     * @param string $baseUrl where the site answers, for links, for example `https://example.org`
     */
    public function __construct(
        public readonly Site $site,
        public readonly ContainerInterface $services,
        public readonly FirstAdmin $admin,
        public readonly string $baseUrl,
        public readonly bool $demo = false,
    ) {
    }

    /**
     * The first admin's user id, once their account exists.
     */
    public function adminId(): ?int
    {
        return $this->adminId;
    }

    public function setAdminId(int $id): void
    {
        $this->adminId = $id;
    }

    /**
     * Tells later steps the id of something a step made, for example `demo.picture`.
     */
    public function share(string $name, int $id): void
    {
        $this->shared[$name] = $id;
    }

    public function shared(string $name): ?int
    {
        return $this->shared[$name] ?? null;
    }

    /**
     * A sentence for the operator about what was set up.
     */
    public function report(string $line): void
    {
        $this->lines[] = $line;
    }

    /**
     * @return list<string>
     */
    public function lines(): array
    {
        return $this->lines;
    }
}
