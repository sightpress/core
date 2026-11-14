<?php

declare(strict_types=1);

namespace SightPress\Core\Module;

use SightPress\Core\Module\Registrar\ModuleScopedRegistrar;
use SightPress\Module\Access\Permission;
use SightPress\Module\Admin\AdminMenuItem;
use SightPress\Module\Block\BlockType;
use SightPress\Module\Console\Command;
use SightPress\Module\Exception\InvalidValueException;
use SightPress\Module\Migration\MigrationProvider;
use SightPress\Module\Routing\Route;
use SightPress\Module\Setting\Setting;

/**
 * Everything the modules that are on registered, each item tied to its module. Built once per container by
 * {@see self::register()}; the kernel turns it into services, routes, views, migrations, listeners and commands.
 *
 * Two modules can't register the same service id, route name, method and path, permission, setting, admin menu
 * entry, view namespace or migration namespace: the error names both modules.
 */
final class ModuleRegistry
{
    /** @var array<string, list<string>> module id => ids of the modules it requires */
    private array $requires = [];

    /** @var array<string, Registered<ServiceDefinition>> */
    private array $services = [];

    /** @var array<string, Registered<Route>> by route name */
    private array $routes = [];

    /** @var array<string, string> "METHOD path" => route name */
    private array $routePaths = [];

    /** @var array<class-string<BlockType>, Registered<class-string<BlockType>>> */
    private array $blockTypes = [];

    /** @var array<string, Registered<Permission>> */
    private array $permissions = [];

    /** @var array<string, Registered<MigrationProvider>> by namespace */
    private array $migrations = [];

    /** @var array<string, Registered<AdminMenuItem>> */
    private array $adminMenu = [];

    /** @var array<string, Registered<ViewPath>> */
    private array $views = [];

    /** @var list<Registered<Listener>> */
    private array $listeners = [];

    /** @var array<class-string<Command>, Registered<class-string<Command>>> */
    private array $commands = [];

    /** @var array<string, Registered<Setting>> */
    private array $settings = [];

    /**
     * Lets every module register, in the given (dependency) order.
     *
     * @param list<LoadedModule> $modules
     */
    public static function register(array $modules): self
    {
        $registry = new self();
        foreach ($modules as $module) {
            $id = $module->id();
            $registry->requires[$id] = $module->requiredIds();
            try {
                $module->module->register(new ModuleScopedRegistrar($id, $registry));
            } catch (InvalidValueException $exception) {
                throw new ModuleException(
                    sprintf('Module "%s" could not register: %s', $id, $exception->getMessage()),
                    previous: $exception,
                );
            }
        }

        return $registry;
    }

    /**
     * @return list<string> ids of the modules that registered, in order
     */
    public function modules(): array
    {
        return array_keys($this->requires);
    }

    /**
     * @param non-empty-string $module
     */
    public function addService(string $module, ServiceDefinition $service): void
    {
        $this->unique($this->services, $service->id, 'Service', $module);
        $this->services[$service->id] = new Registered($module, $service);
    }

    /**
     * @param non-empty-string $module
     */
    public function addRoute(string $module, Route $route): void
    {
        $this->unique($this->routes, $route->name, 'Route', $module);
        foreach ($route->methods as $method) {
            $key = $method . ' ' . $route->path;
            if (isset($this->routePaths[$key])) {
                $other = $this->routes[$this->routePaths[$key]];
                throw new ModuleException(sprintf(
                    'Route "%s" of module "%s" answers %s, like route "%s" of module "%s".',
                    $route->name,
                    $module,
                    $key,
                    $other->item->name,
                    $other->module,
                ));
            }
        }
        foreach ($route->methods as $method) {
            $this->routePaths[$method . ' ' . $route->path] = $route->name;
        }
        $this->routes[$route->name] = new Registered($module, $route);
    }

    /**
     * @param non-empty-string        $module
     * @param class-string<BlockType> $blockType
     */
    public function addBlockType(string $module, string $blockType): void
    {
        $this->unique($this->blockTypes, $blockType, 'Block type', $module);
        $this->blockTypes[$blockType] = new Registered($module, $blockType);
    }

    /**
     * @param non-empty-string $module
     */
    public function addPermission(string $module, Permission $permission): void
    {
        $this->unique($this->permissions, $permission->name, 'Permission', $module);
        $this->permissions[$permission->name] = new Registered($module, $permission);
    }

    /**
     * @param non-empty-string $module
     */
    public function addMigrations(string $module, MigrationProvider $provider): void
    {
        $namespace = $provider->namespace();
        $this->unique($this->migrations, $namespace, 'Migration namespace', $module);
        $this->migrations[$namespace] = new Registered($module, $provider);
    }

    /**
     * @param non-empty-string $module
     */
    public function addAdminMenuItem(string $module, AdminMenuItem $item): void
    {
        $this->unique($this->adminMenu, $item->id, 'Admin menu entry', $module);
        $this->adminMenu[$item->id] = new Registered($module, $item);
    }

    /**
     * @param non-empty-string $module
     */
    public function addView(string $module, ViewPath $view): void
    {
        $this->unique($this->views, $view->namespace, 'View namespace', $module);
        $this->views[$view->namespace] = new Registered($module, $view);
    }

    /**
     * @param non-empty-string $module
     */
    public function addListener(string $module, Listener $listener): void
    {
        $this->listeners[] = new Registered($module, $listener);
    }

    /**
     * @param non-empty-string      $module
     * @param class-string<Command> $command
     */
    public function addCommand(string $module, string $command): void
    {
        $this->unique($this->commands, $command, 'Command', $module);
        $this->commands[$command] = new Registered($module, $command);
    }

    /**
     * @param non-empty-string $module
     */
    public function addSetting(string $module, Setting $setting): void
    {
        $this->unique($this->settings, $setting->key, 'Setting', $module);
        $this->settings[$setting->key] = new Registered($module, $setting);
    }

    /**
     * @return array<string, Registered<ServiceDefinition>>
     */
    public function services(): array
    {
        return $this->services;
    }

    /**
     * @return list<Registered<Route>>
     */
    public function routes(): array
    {
        return array_values($this->routes);
    }

    /**
     * @return list<Registered<class-string<BlockType>>>
     */
    public function blockTypes(): array
    {
        return array_values($this->blockTypes);
    }

    /**
     * @return list<Registered<Permission>>
     */
    public function permissions(): array
    {
        return array_values($this->permissions);
    }

    /**
     * @return list<Registered<MigrationProvider>>
     */
    public function migrations(): array
    {
        return array_values($this->migrations);
    }

    /**
     * @return list<Registered<AdminMenuItem>>
     */
    public function adminMenu(): array
    {
        return array_values($this->adminMenu);
    }

    /**
     * @return list<Registered<ViewPath>>
     */
    public function views(): array
    {
        return array_values($this->views);
    }

    /**
     * @return list<Registered<Listener>>
     */
    public function listeners(): array
    {
        return $this->listeners;
    }

    /**
     * @return list<Registered<class-string<Command>>>
     */
    public function commands(): array
    {
        return array_values($this->commands);
    }

    /**
     * @return list<Registered<Setting>>
     */
    public function settings(): array
    {
        return array_values($this->settings);
    }

    /**
     * Whether a module may use a service: core services and its own services always; another module's service
     * only when that service is public and the module requires its owner (ADR-0009).
     */
    public function canUse(string $module, string $serviceId): bool
    {
        $service = $this->services[$serviceId] ?? null;
        if ($service === null || $service->module === $module) {
            return true;
        }

        return $service->item->public && in_array($service->module, $this->requires[$module] ?? [], true);
    }

    public function assertCanUse(string $module, string $serviceId): void
    {
        if ($this->canUse($module, $serviceId)) {
            return;
        }

        $service = $this->services[$serviceId];
        throw new ModuleException($service->item->public
            ? sprintf(
                'Module "%s" uses service "%s" of module "%s" but does not require that module. '
                . 'Add "%s" to the requirements in its manifest.',
                $module,
                $serviceId,
                $service->module,
                $service->module,
            )
            : sprintf(
                'Module "%s" uses service "%s", which is private to module "%s".',
                $module,
                $serviceId,
                $service->module,
            ));
    }

    /**
     * @param array<string, Registered<mixed>> $registered
     */
    private function unique(array $registered, string $key, string $what, string $module): void
    {
        if (isset($registered[$key])) {
            throw new ModuleException(sprintf(
                '%s "%s" is registered twice: by module "%s" and by module "%s".',
                $what,
                $key,
                $registered[$key]->module,
                $module,
            ));
        }
    }
}
