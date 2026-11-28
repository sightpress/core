<?php

declare(strict_types=1);

namespace SightPress\Core\Module;

use SightPress\Core\Module\Registrar\ModuleScopedRegistrar;
use SightPress\Module\Access\Permission;
use SightPress\Module\Admin\AdminMenuItem;
use SightPress\Module\Assistant\AssistantProvider;
use SightPress\Module\Assistant\AssistantTask;
use SightPress\Module\Block\BlockIcon;
use SightPress\Module\Block\BlockType;
use SightPress\Module\Block\BlockVariant;
use SightPress\Module\Console\Command;
use SightPress\Module\Dashboard\DashboardWidget;
use SightPress\Module\Exception\InvalidValueException;
use SightPress\Module\Migration\MigrationProvider;
use SightPress\Module\Routing\Route;
use SightPress\Module\Search\SearchBackend;
use SightPress\Module\Schedule\ScheduledJob;
use SightPress\Module\Search\SearchDocumentProvider;
use SightPress\Module\Seo\SeoProvider;
use SightPress\Module\Sms\SmsProvider;
use SightPress\Module\Block\CodeLanguage;
use SightPress\Module\Placeholder\Placeholder;
use SightPress\Module\Privacy\ConsentPurpose;
use SightPress\Module\Privacy\CookieDeclaration;
use SightPress\Module\Privacy\PersonalDataProvider;
use SightPress\Module\Privacy\ProcessorSource;
use SightPress\Module\Setting\Setting;
use Throwable;

/**
 * Everything the modules that are on registered, each item tied to its module. Built once per container by
 * {@see self::register()}; the kernel turns it into services, routes, views, migrations, listeners and commands.
 *
 * Two modules can't register the same service id, route name, method and path, permission, setting, admin menu
 * entry, view namespace, migration namespace, search provider, dashboard widget, assistant provider or task, and
 * only one module may replace the search back-end: the error names both modules.
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

    /** @var array<class-string<SearchDocumentProvider>, Registered<class-string<SearchDocumentProvider>>> */
    private array $searchProviders = [];

    /** @var Registered<class-string<SearchBackend>>|null */
    private ?Registered $searchBackend = null;

    /** @var array<class-string<DashboardWidget>, Registered<class-string<DashboardWidget>>> */
    private array $dashboardWidgets = [];

    /** @var array<class-string<AssistantProvider>, Registered<class-string<AssistantProvider>>> */
    private array $assistantProviders = [];

    /** @var array<class-string<AssistantTask>, Registered<class-string<AssistantTask>>> */
    private array $assistantTasks = [];

    /** @var array<class-string<SeoProvider>, Registered<class-string<SeoProvider>>> */
    private array $seoProviders = [];

    /** @var array<class-string<SmsProvider>, Registered<class-string<SmsProvider>>> */
    private array $smsProviders = [];

    /** @var array<class-string<ScheduledJob>, Registered<class-string<ScheduledJob>>> */
    private array $scheduledJobs = [];

    /** @var array<string, Registered<string>> folder → folder */
    private array $translations = [];

    /** @var array<string, Registered<array{blockType: string, variant: BlockVariant}>> `type:variant` → variant */
    private array $blockVariants = [];

    /** @var array<string, Registered<BlockIcon>> */
    private array $blockIcons = [];

    /** @var array<class-string<PersonalDataProvider>, Registered<class-string<PersonalDataProvider>>> */
    private array $personalDataProviders = [];

    /** @var array<string, Registered<CookieDeclaration>> name → cookie */
    private array $cookies = [];

    /** @var array<class-string<ProcessorSource>, Registered<class-string<ProcessorSource>>> */
    private array $processorSources = [];

    /** @var array<string, Registered<ConsentPurpose>> key → purpose */
    private array $consentPurposes = [];

    /** @var array<string, Registered<Placeholder>> name → placeholder */
    private array $placeholders = [];

    /** @var array<string, Registered<CodeLanguage>> id or alias → language */
    private array $codeLanguages = [];

    /** @var list<UnavailableModule> */
    private array $failures = [];

    /**
     * Lets every module register, in the given (dependency) order. An external module that fails to register is
     * left out with everything it registered so far, and so are the modules that need it (ADR-0065); a failure in
     * one of core's modules stops the tenant.
     *
     * @param list<LoadedModule> $modules
     */
    public static function register(array $modules): self
    {
        $registry = new self();
        foreach ($modules as $module) {
            $id = $module->id();
            $failed = array_values(array_intersect($module->requiredIds(), array_map(static fn (UnavailableModule $failure): ?string => $failure->id, $registry->failures)));
            if ($failed !== []) {
                $registry->failures[] = new UnavailableModule($module->source, $id, sprintf('It needs module "%s", which could not start.', $failed[0]));
                continue;
            }
            $before = clone $registry;
            try {
                $registry->requires[$id] = $module->requiredIds();
                $module->module->register(new ModuleScopedRegistrar($id, $registry));
            } catch (Throwable $exception) {
                if (!$module->external) {
                    throw $exception instanceof InvalidValueException ? new ModuleException(
                        sprintf('Module "%s" could not register: %s', $id, $exception->getMessage()),
                        previous: $exception,
                    ) : $exception;
                }
                $registry = $before;
                $registry->failures[] = new UnavailableModule($module->source, $id, sprintf('It could not register: %s', $exception->getMessage()));
            }
        }

        return $registry;
    }

    /**
     * The external modules that were left out because they, or a module they need, failed to register.
     *
     * @return list<UnavailableModule>
     */
    public function failures(): array
    {
        return $this->failures;
    }

    /**
     * @return list<string> ids of the modules that registered, in order
     */
    public function modules(): array
    {
        return array_keys($this->requires);
    }

    /**
     * Whether a module registered here, so it is on.
     */
    public function has(string $module): bool
    {
        return isset($this->requires[$module]);
    }

    /**
     * What only the given modules registered, for a site that has some optional modules off (ADR-0034): routes,
     * listeners, admin menu entries, settings, migrations and the rest of the other modules are left out.
     *
     * @param list<string> $modules ids of the modules that are on
     */
    public function only(array $modules): self
    {
        $on = array_fill_keys($modules, true);

        $copy = clone $this;
        $copy->requires = array_intersect_key($this->requires, $on);
        $copy->services = self::keep($this->services, $on);
        $copy->routes = self::keep($this->routes, $on);
        $copy->routePaths = array_filter($this->routePaths, static fn (string $name): bool => isset($copy->routes[$name]));
        $copy->blockTypes = self::keep($this->blockTypes, $on);
        $copy->permissions = self::keep($this->permissions, $on);
        $copy->migrations = self::keep($this->migrations, $on);
        $copy->adminMenu = self::keep($this->adminMenu, $on);
        $copy->views = self::keep($this->views, $on);
        $copy->listeners = array_values(self::keep($this->listeners, $on));
        $copy->commands = self::keep($this->commands, $on);
        $copy->settings = self::keep($this->settings, $on);
        $copy->searchProviders = self::keep($this->searchProviders, $on);
        $copy->searchBackend = $this->searchBackend !== null && isset($on[$this->searchBackend->module]) ? $this->searchBackend : null;
        $copy->dashboardWidgets = self::keep($this->dashboardWidgets, $on);
        $copy->assistantProviders = self::keep($this->assistantProviders, $on);
        $copy->assistantTasks = self::keep($this->assistantTasks, $on);
        $copy->seoProviders = self::keep($this->seoProviders, $on);
        $copy->smsProviders = self::keep($this->smsProviders, $on);
        $copy->scheduledJobs = self::keep($this->scheduledJobs, $on);
        $copy->translations = self::keep($this->translations, $on);
        $copy->blockVariants = self::keep($this->blockVariants, $on);
        $copy->blockIcons = self::keep($this->blockIcons, $on);
        $copy->personalDataProviders = self::keep($this->personalDataProviders, $on);
        $copy->cookies = self::keep($this->cookies, $on);
        $copy->processorSources = self::keep($this->processorSources, $on);
        $copy->consentPurposes = self::keep($this->consentPurposes, $on);
        $copy->placeholders = self::keep($this->placeholders, $on);
        $copy->codeLanguages = self::keep($this->codeLanguages, $on);

        return $copy;
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
     * @param non-empty-string                     $module
     * @param class-string<SearchDocumentProvider> $provider
     */
    public function addSearchProvider(string $module, string $provider): void
    {
        $this->unique($this->searchProviders, $provider, 'Search document provider', $module);
        $this->searchProviders[$provider] = new Registered($module, $provider);
    }

    /**
     * @param non-empty-string            $module
     * @param class-string<SearchBackend> $backend
     */
    public function useSearchBackend(string $module, string $backend): void
    {
        if ($this->searchBackend !== null) {
            throw new ModuleException(sprintf(
                'Module "%s" replaces the search back-end with %s, but module "%s" already replaced it with %s. '
                . 'Turn one of them off.',
                $module,
                $backend,
                $this->searchBackend->module,
                $this->searchBackend->item,
            ));
        }
        $this->searchBackend = new Registered($module, $backend);
    }

    /**
     * @param non-empty-string              $module
     * @param class-string<DashboardWidget> $widget
     */
    public function addDashboardWidget(string $module, string $widget): void
    {
        $this->unique($this->dashboardWidgets, $widget, 'Dashboard widget', $module);
        $this->dashboardWidgets[$widget] = new Registered($module, $widget);
    }

    /**
     * @param non-empty-string                $module
     * @param class-string<AssistantProvider> $provider
     */
    public function addAssistantProvider(string $module, string $provider): void
    {
        $this->unique($this->assistantProviders, $provider, 'Assistant provider', $module);
        $this->assistantProviders[$provider] = new Registered($module, $provider);
    }

    /**
     * @param non-empty-string            $module
     * @param class-string<AssistantTask> $task
     */
    public function addAssistantTask(string $module, string $task): void
    {
        $this->unique($this->assistantTasks, $task, 'Assistant task', $module);
        $this->assistantTasks[$task] = new Registered($module, $task);
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
     * @return list<Registered<class-string<SearchDocumentProvider>>>
     */
    public function searchProviders(): array
    {
        return array_values($this->searchProviders);
    }

    /**
     * The back-end a module put in place of core's built-in one, or null.
     *
     * @return Registered<class-string<SearchBackend>>|null
     */
    public function searchBackend(): ?Registered
    {
        return $this->searchBackend;
    }

    /**
     * @return list<Registered<class-string<DashboardWidget>>>
     */
    public function dashboardWidgets(): array
    {
        return array_values($this->dashboardWidgets);
    }

    /**
     * @return list<Registered<class-string<AssistantProvider>>>
     */
    public function assistantProviders(): array
    {
        return array_values($this->assistantProviders);
    }

    /**
     * @return list<Registered<class-string<AssistantTask>>>
     */
    public function assistantTasks(): array
    {
        return array_values($this->assistantTasks);
    }

    /**
     * @param non-empty-string $module
     * @param class-string<SeoProvider> $provider
     */
    public function addSeoProvider(string $module, string $provider): void
    {
        $this->unique($this->seoProviders, $provider, 'SEO provider', $module);
        $this->seoProviders[$provider] = new Registered($module, $provider);
    }

    /**
     * @param non-empty-string $module
     * @param class-string<SmsProvider> $provider
     */
    public function addSmsProvider(string $module, string $provider): void
    {
        $this->unique($this->smsProviders, $provider, 'Text message provider', $module);
        $this->smsProviders[$provider] = new Registered($module, $provider);
    }

    /**
     * @param non-empty-string $module
     * @param class-string<ScheduledJob> $job
     */
    public function addScheduledJob(string $module, string $job): void
    {
        $this->unique($this->scheduledJobs, $job, 'Scheduled job', $module);
        $this->scheduledJobs[$job] = new Registered($module, $job);
    }

    /**
     * @param non-empty-string $module
     */
    public function addTranslations(string $module, string $directory): void
    {
        $this->unique($this->translations, $directory, 'Translation folder', $module);
        $this->translations[$directory] = new Registered($module, $directory);
    }

    /**
     * @param non-empty-string $module
     */
    public function addBlockVariant(string $module, string $blockType, BlockVariant $variant): void
    {
        $key = $blockType . ':' . $variant->name;
        $this->unique($this->blockVariants, $key, 'Block variant', $module);
        $this->blockVariants[$key] = new Registered($module, ['blockType' => $blockType, 'variant' => $variant]);
    }

    /**
     * @param non-empty-string $module
     */
    public function addBlockIcon(string $module, BlockIcon $icon): void
    {
        $this->unique($this->blockIcons, $icon->name, 'Icon', $module);
        $this->blockIcons[$icon->name] = new Registered($module, $icon);
    }

    /**
     * @param non-empty-string $module
     * @param class-string<PersonalDataProvider> $provider
     */
    public function addPersonalDataProvider(string $module, string $provider): void
    {
        $this->unique($this->personalDataProviders, $provider, 'Personal data provider', $module);
        $this->personalDataProviders[$provider] = new Registered($module, $provider);
    }

    /**
     * @param non-empty-string $module
     */
    public function addCookie(string $module, CookieDeclaration $cookie): void
    {
        $this->unique($this->cookies, $cookie->name, 'Cookie', $module);
        $this->cookies[$cookie->name] = new Registered($module, $cookie);
    }

    /**
     * @param non-empty-string $module
     * @param class-string<ProcessorSource> $source
     */
    public function addProcessorSource(string $module, string $source): void
    {
        $this->unique($this->processorSources, $source, 'Processor source', $module);
        $this->processorSources[$source] = new Registered($module, $source);
    }

    /**
     * @param non-empty-string $module
     */
    public function addConsentPurpose(string $module, ConsentPurpose $purpose): void
    {
        $this->unique($this->consentPurposes, $purpose->key, 'Consent purpose', $module);
        $this->consentPurposes[$purpose->key] = new Registered($module, $purpose);
    }

    /**
     * @param non-empty-string $module
     */
    public function addPlaceholder(string $module, Placeholder $placeholder): void
    {
        $this->unique($this->placeholders, $placeholder->name, 'Placeholder', $module);
        $this->placeholders[$placeholder->name] = new Registered($module, $placeholder);
    }

    /**
     * @param non-empty-string $module
     */
    public function addCodeLanguage(string $module, CodeLanguage $language): void
    {
        $ids = [$language->id, ...$language->aliases];
        foreach ($ids as $id) {
            $this->unique($this->codeLanguages, $id, 'Code language', $module);
        }
        foreach ($ids as $id) {
            $this->codeLanguages[$id] = new Registered($module, $language);
        }
    }

    /**
     * @return list<Registered<class-string<PersonalDataProvider>>>
     */
    public function personalDataProviders(): array
    {
        return array_values($this->personalDataProviders);
    }

    /**
     * @return list<Registered<CookieDeclaration>>
     */
    public function cookies(): array
    {
        return array_values($this->cookies);
    }

    /**
     * @return list<Registered<class-string<ProcessorSource>>>
     */
    public function processorSources(): array
    {
        return array_values($this->processorSources);
    }

    /**
     * @return list<Registered<ConsentPurpose>>
     */
    public function consentPurposes(): array
    {
        return array_values($this->consentPurposes);
    }

    /**
     * @return list<Registered<Placeholder>>
     */
    public function placeholders(): array
    {
        return array_values($this->placeholders);
    }

    /**
     * Each code language once (not once per alias), in registration order.
     *
     * @return list<Registered<CodeLanguage>>
     */
    public function codeLanguages(): array
    {
        return array_values(array_filter(
            $this->codeLanguages,
            static fn (Registered $registered, string $id): bool => $registered->item->id === $id,
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    /**
     * @return list<Registered<class-string<SeoProvider>>>
     */
    public function seoProviders(): array
    {
        return array_values($this->seoProviders);
    }

    /**
     * @return list<Registered<class-string<SmsProvider>>>
     */
    public function smsProviders(): array
    {
        return array_values($this->smsProviders);
    }

    /**
     * @return list<Registered<class-string<ScheduledJob>>>
     */
    public function scheduledJobs(): array
    {
        return array_values($this->scheduledJobs);
    }

    /**
     * Folders of module translations, in registration order.
     *
     * @return list<string>
     */
    public function translations(): array
    {
        return array_keys($this->translations);
    }

    /**
     * @return list<Registered<array{blockType: string, variant: BlockVariant}>>
     */
    public function blockVariants(): array
    {
        return array_values($this->blockVariants);
    }

    /**
     * @return list<Registered<BlockIcon>>
     */
    public function blockIcons(): array
    {
        return array_values($this->blockIcons);
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
     * @template TKey of array-key
     * @template TItem
     *
     * @param array<TKey, Registered<TItem>> $registered
     * @param array<string, true>            $on
     *
     * @return array<TKey, Registered<TItem>>
     */
    private static function keep(array $registered, array $on): array
    {
        return array_filter($registered, static fn (Registered $item): bool => isset($on[$item->module]));
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
