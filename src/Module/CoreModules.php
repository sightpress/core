<?php

declare(strict_types=1);

namespace SightPress\Core\Module;

use SightPress\Core\Auth\AuthModule;
use SightPress\Core\Media\MediaModule;
use SightPress\Core\Rbac\RbacModule;
use SightPress\Core\Site\SiteModule;
use SightPress\Core\Theme\ThemeModule;
use SightPress\Core\User\UserModule;

/**
 * The static list of core's own feature modules (ADR-0009). Each feature (users, roles, pages, blocks, media …)
 * adds its `<Context>Module` here when it is built; they register in dependency order, not list order.
 */
final class CoreModules
{
    /**
     * @return list<ModuleCandidate>
     */
    public static function candidates(): array
    {
        return [
            new ModuleCandidate(new SiteModule(), 'sightpress/core'),
            new ModuleCandidate(new UserModule(), 'sightpress/core'),
            new ModuleCandidate(new RbacModule(), 'sightpress/core'),
            new ModuleCandidate(new AuthModule(), 'sightpress/core'),
            new ModuleCandidate(new ThemeModule(), 'sightpress/core'),
            new ModuleCandidate(new MediaModule(), 'sightpress/core'),
        ];
    }

    /**
     * Folders with the entity classes of core's modules, mapped with attributes in every site's entity manager.
     * The module contract has no way to register entities yet; see ADR-0016.
     *
     * @return list<string>
     */
    public static function entityPaths(): array
    {
        $src = dirname(__DIR__);

        return [$src . '/User/Entity', $src . '/Rbac/Entity', $src . '/Media/Entity'];
    }
}
