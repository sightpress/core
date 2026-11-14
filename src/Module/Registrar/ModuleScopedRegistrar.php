<?php

declare(strict_types=1);

namespace SightPress\Core\Module\Registrar;

use SightPress\Core\Module\ModuleRegistry;
use SightPress\Module\ModuleRegistrar;
use SightPress\Module\Registrar\AdminMenuRegistrar;
use SightPress\Module\Registrar\BlockTypeRegistrar;
use SightPress\Module\Registrar\CommandRegistrar;
use SightPress\Module\Registrar\MigrationRegistrar;
use SightPress\Module\Registrar\PermissionRegistrar;
use SightPress\Module\Registrar\RouteRegistrar;
use SightPress\Module\Registrar\ServiceRegistrar;
use SightPress\Module\Registrar\SettingRegistrar;
use SightPress\Module\Registrar\SubscriberRegistrar;
use SightPress\Module\Registrar\ViewRegistrar;

/**
 * Core's {@see ModuleRegistrar}: hands one module the sub-registrars that record what it adds in the
 * {@see ModuleRegistry}, tied to that module.
 */
final readonly class ModuleScopedRegistrar implements ModuleRegistrar
{
    /**
     * @param non-empty-string $module
     */
    public function __construct(
        private string $module,
        private ModuleRegistry $registry,
    ) {
    }

    public function services(): ServiceRegistrar
    {
        return new ServiceCollector($this->module, $this->registry);
    }

    public function routes(): RouteRegistrar
    {
        return new RouteCollector($this->module, $this->registry);
    }

    public function blockTypes(): BlockTypeRegistrar
    {
        return new BlockTypeCollector($this->module, $this->registry);
    }

    public function permissions(): PermissionRegistrar
    {
        return new PermissionCollector($this->module, $this->registry);
    }

    public function migrations(): MigrationRegistrar
    {
        return new MigrationCollector($this->module, $this->registry);
    }

    public function adminMenu(): AdminMenuRegistrar
    {
        return new AdminMenuCollector($this->module, $this->registry);
    }

    public function views(): ViewRegistrar
    {
        return new ViewCollector($this->module, $this->registry);
    }

    public function subscribers(): SubscriberRegistrar
    {
        return new SubscriberCollector($this->module, $this->registry);
    }

    public function commands(): CommandRegistrar
    {
        return new CommandCollector($this->module, $this->registry);
    }

    public function settings(): SettingRegistrar
    {
        return new SettingCollector($this->module, $this->registry);
    }
}
