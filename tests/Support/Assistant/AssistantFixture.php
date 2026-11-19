<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Assistant;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use SightPress\Core\Assistant\Assistant;
use SightPress\Core\Assistant\AssistantBudget;
use SightPress\Core\Assistant\AssistantSettings;
use SightPress\Core\Assistant\AssistantSetup;
use SightPress\Core\Assistant\AssistantUsageStore;
use SightPress\Core\Assistant\Migrations\Version20261120090000;
use SightPress\Core\Http\RateLimit\RateLimiters;
use SightPress\Core\Infrastructure\Assistant\AssistantExtensions;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Tests\Support\ArrayContainer;
use SightPress\Core\Tests\Support\ArraySiteSettings;
use SightPress\Core\Tests\Support\FakeModule;
use SightPress\Core\Tests\Support\FixedClock;
use SightPress\Core\Tests\Support\RecordingEvents;
use SightPress\Core\Tests\Support\SiteFixture;
use SightPress\Module\Assistant\AssistantProvider;
use SightPress\Module\Assistant\AssistantTask;
use SightPress\Module\ModuleRegistrar;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * The assistant's services wired by hand around a fake provider and task, with the usage table on SQLite in memory.
 */
final class AssistantFixture
{
    public readonly Connection $connection;

    public readonly ArraySiteSettings $siteSettings;

    public readonly RecordingEvents $events;

    public readonly AssistantSettings $settings;

    public readonly AssistantExtensions $extensions;

    public readonly AssistantSetup $setup;

    public readonly AssistantUsageStore $usage;

    public readonly Assistant $assistant;

    /**
     * @param list<AssistantProvider>  $providers
     * @param list<AssistantTask>      $tasks
     * @param array<mixed>             $tenant     the tenant's `assistant` settings
     * @param array<string, string>    $environment
     * @param array<int, string>       $roles      user id => role
     * @param array<string, string>    $settings   the site's stored settings
     */
    public function __construct(
        array $providers = [],
        array $tasks = [],
        array $tenant = [],
        array $environment = [],
        array $roles = [3 => 'editor', 4 => 'admin', 5 => 'member'],
        array $settings = [],
        public readonly FixedClock $clock = new FixedClock(new DateTimeImmutable('2026-11-20 10:00:00 UTC')),
        int $rateLimit = 20,
    ) {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schema = new Schema();
        new Version20261120090000($this->connection, new \Psr\Log\NullLogger())->up($schema);
        $this->connection->createSchemaManager()->createTable($schema->getTable(AssistantUsageStore::TABLE));

        $entries = [];
        foreach ($providers as $provider) {
            $entries[$provider::class] = $provider;
        }
        foreach ($tasks as $task) {
            $entries[$task::class] = $task;
        }
        $this->extensions = new AssistantExtensions(new ArrayContainer($entries), self::registry($providers, $tasks));

        $this->siteSettings = new ArraySiteSettings($settings);
        $this->events = new RecordingEvents();
        $this->settings = new AssistantSettings($this->siteSettings, SiteFixture::secretBox(), $this->events);
        $config = TenantConfig::fromArray(['assistant' => $tenant], '/tmp/project', ['APP_ENV' => 'production'] + $environment);
        $this->setup = new AssistantSetup($this->settings, $this->extensions, $config);
        $this->usage = new AssistantUsageStore($this->connection);
        $this->assistant = new Assistant(
            $this->setup,
            $this->extensions,
            new AssistantBudget($this->usage, $this->clock),
            $this->usage,
            new RateLimiters(new InMemoryStorage(), ['assistant' => ['policy' => 'fixed_window', 'limit' => max(1, $rateLimit), 'interval' => '10 minutes']]),
            new FakeRoleGrants($roles),
            $this->settings,
            $this->events,
            $this->clock,
            SiteFixture::site(),
        );
    }

    /**
     * A registry in which a test module registered the providers and tasks (one of each class: the
     * {@see ArrayContainer} hands out the instances by class).
     *
     * @param list<AssistantProvider> $providers
     * @param list<AssistantTask>     $tasks
     */
    private static function registry(array $providers, array $tasks): ModuleRegistry
    {
        $module = new FakeModule('acme.ai', register: static function (ModuleRegistrar $registrar) use ($providers, $tasks): void {
            foreach ($providers as $provider) {
                $registrar->assistant()->addProvider($provider::class);
            }
            foreach ($tasks as $task) {
                $registrar->assistant()->addTask($task::class);
            }
        });

        return ModuleRegistry::register(new ModuleLoader('0.2.0')->catalogue([new ModuleCandidate($module, 'acme/ai')])->resolve(new ModuleSelection()));
    }
}
