<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Modules;

use RuntimeException;
use SightPress\Module\ModuleInterface;
use SightPress\Module\ModuleManifest;
use SightPress\Module\ModuleRegistrar;

/**
 * An external module that fails at every step a module can fail at, chosen with {@see $failAt}: being created, giving
 * its manifest, or registering (after it registered a route, which must not stay).
 */
final class BrokenModule implements ModuleInterface
{
    public const string ID = 'acme.broken';

    /** 'create', 'manifest', 'register' or '' (works) */
    public static string $failAt = 'create';

    public function __construct()
    {
        if (self::$failAt === 'create') {
            throw new RuntimeException('The module could not connect to its licence server.');
        }
    }

    public function manifest(): ModuleManifest
    {
        if (self::$failAt === 'manifest') {
            throw new RuntimeException('The manifest file is missing.');
        }

        return new ModuleManifest(self::ID, '1.0.0');
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $registrar->routes()->add(new \SightPress\Module\Routing\Route('acme.broken.home', '/broken', HelloHandler::class));
        if (self::$failAt === 'register') {
            throw new RuntimeException('A required setting is missing.');
        }
    }
}
