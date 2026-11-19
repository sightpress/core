<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Search;

use Closure;
use SightPress\Core\Infrastructure\Search\SearchExtensions;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Core\Search\Analysis\TextAnalyzers;
use SightPress\Core\Search\DatabaseSearchBackend;
use SightPress\Core\Search\SearchIndex;
use SightPress\Core\Tests\Support\ArrayContainer;
use SightPress\Core\Tests\Support\ArraySiteSettings;
use SightPress\Core\Tests\Support\CoreSiteDatabase;
use SightPress\Core\Tests\Support\FakeModule;
use SightPress\Core\Tests\Support\FixedClock;
use SightPress\Core\Tests\Support\PageFixture;
use SightPress\Core\Tenant\Site;
use SightPress\Module\ModuleRegistrar;

/**
 * A site's search index for unit tests: core's database back-end on an in-memory SQLite database (or a recording
 * back-end a module put in its place) and the fake `acme.events` provider.
 */
final class SearchFixture
{
    public readonly CoreSiteDatabase $database;

    public readonly FakeSearchProvider $provider;

    public readonly ArraySiteSettings $settings;

    public readonly Site $site;

    public readonly DatabaseSearchBackend $backend;

    /**
     * @param list<string>     $locales
     * @param non-empty-string $source  of the fake provider's documents
     */
    public function __construct(public readonly ?RecordingSearchBackend $replacement = null, array $locales = ['en-GB', 'pt-PT'], string $source = 'acme.events')
    {
        $this->database = new CoreSiteDatabase();
        $this->provider = new FakeSearchProvider($source);
        $this->settings = new ArraySiteSettings();
        $this->site = PageFixture::site($locales);
        $this->backend = new DatabaseSearchBackend($this->database->connection, new TextAnalyzers(), new FixedClock());
    }

    public function extensions(): SearchExtensions
    {
        $replacement = $this->replacement;
        $register = static function (ModuleRegistrar $registrar) use ($replacement): void {
            $registrar->search()->addProvider(FakeSearchProvider::class);
            if ($replacement !== null) {
                $registrar->search()->useBackend(RecordingSearchBackend::class);
            }
        };

        return new SearchExtensions(
            new ArrayContainer(array_filter([FakeSearchProvider::class => $this->provider, RecordingSearchBackend::class => $replacement])),
            self::registry($register),
        );
    }

    public function index(): SearchIndex
    {
        return new SearchIndex($this->extensions(), $this->backend, $this->site, $this->settings);
    }

    /**
     * @param Closure(ModuleRegistrar): void $register
     */
    public static function registry(Closure $register): ModuleRegistry
    {
        return ModuleRegistry::register(new ModuleLoader('0.2.0')->catalogue([
            new ModuleCandidate(new FakeModule('acme.events', register: $register), 'acme/events'),
        ])->resolve(new ModuleSelection()));
    }
}
