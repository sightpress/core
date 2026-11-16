<?php

declare(strict_types=1);

namespace SightPress\Core\Page;

use SightPress\Module\ModuleInterface;
use SightPress\Module\ModuleManifest;
use SightPress\Module\ModuleRegistrar;
use SightPress\Module\ModuleRequirement;

/**
 * Pages: the `pages` tree, a translation per locale with its blocks, and a revision for every save. The
 * {@see PageEditor} creates, writes and moves pages; the {@see PageRepository} finds them. Always on. Its
 * permissions (`page.*`) are in core's catalogue, and the RBAC module's page voter decides who may do what.
 */
final class PageModule implements ModuleInterface
{
    public const string ID = 'page';

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            self::ID,
            '0.1.0',
            [new ModuleRequirement('site', '^0.1'), new ModuleRequirement('rbac', '^0.1'), new ModuleRequirement('block', '^0.1')],
            required: true,
        );
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $registrar->migrations()->add(new PageMigrations());

        $services = $registrar->services();
        $services->autowire(PageRepository::class, public: true);
        $services->autowire(PageEditor::class, public: true);
    }
}
