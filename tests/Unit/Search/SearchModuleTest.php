<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Search;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Module\CoreModules;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Module\Event\PagePurged;
use SightPress\Module\Event\PageTransitioned;
use SightPress\Core\Page\PageSearchDocuments;
use SightPress\Core\Search\Command\ReindexSearchCommand;
use SightPress\Core\Search\Command\SearchCommand;
use SightPress\Core\Search\Command\SearchStatusCommand;
use SightPress\Core\Search\SearchIndex;
use SightPress\Core\Search\SearchIndexer;
use SightPress\Core\Search\SearchMigrations;
use SightPress\Core\Search\SearchModule;
use SightPress\Core\Search\SearchReindexJob;
use SightPress\Core\Search\SiteSearch;
use SightPress\Core\Site\Contract\ScheduleDue;

#[CoversClass(SearchModule::class)]
#[CoversClass(SearchMigrations::class)]
#[UsesNamespace('SightPress\Core')]
final class SearchModuleTest extends TestCase
{
    public function testIsOptionalOnByDefaultAndRegistersTheIndexItsListenersAndCommands(): void
    {
        $manifest = new SearchModule()->manifest();
        self::assertSame(['search', false, true], [$manifest->id, $manifest->required, $manifest->enabledByDefault]);
        self::assertSame(['site', 'page'], array_map(static fn ($requirement) => $requirement->moduleId, $manifest->requires));

        $candidates = array_values(array_filter(CoreModules::candidates(), static fn ($candidate) => $candidate->module instanceof SearchModule));
        self::assertSame('Site search', $candidates[0]->about['search']->name);
        self::assertNotSame('', $candidates[0]->about['search']->whenOff);

        $registry = ModuleRegistry::register(new ModuleLoader('0.2.0')->catalogue(CoreModules::candidates())->resolve(new ModuleSelection()));
        self::assertTrue($registry->services()[SearchIndex::class]->item->public);
        self::assertTrue($registry->services()[SiteSearch::class]->item->public);
        $listeners = array_map(static fn ($l) => [$l->item->event, $l->item->listener], array_values(array_filter($registry->listeners(), static fn ($l) => $l->module === 'search')));
        self::assertCount(12, $listeners);
        self::assertContains([\SightPress\Core\Page\Contract\PublicPageRendering::class, \SightPress\Core\Search\Http\SearchFormListener::class], $listeners);
        $routes = array_values(array_filter($registry->routes(), static fn ($r) => $r->module === 'search'));
        self::assertSame(['search.results' => '/search', 'search.results_locale' => '/{locale:[a-z]{2,3}(?:-[a-z0-9]{2,8})*}/search'], array_combine(array_map(static fn ($r) => $r->item->name, $routes), array_map(static fn ($r) => $r->item->path, $routes)));
        $views = array_values(array_filter($registry->views(), static fn ($v) => $v->module === 'search'));
        self::assertFileExists($views[0]->item->directory . '/results.html.twig');
        self::assertContains([PageTransitioned::class, SearchIndexer::class], $listeners);
        self::assertContains([PagePurged::class, SearchIndexer::class], $listeners);
        self::assertContains([ScheduleDue::class, SearchReindexJob::class], $listeners);
        self::assertSame(
            [ReindexSearchCommand::class, SearchStatusCommand::class, SearchCommand::class],
            array_values(array_map(static fn ($c) => $c->item, array_filter($registry->commands(), static fn ($c) => $c->module === 'search'))),
        );
        self::assertSame([['page', PageSearchDocuments::class]], array_map(static fn ($p) => [$p->module, $p->item], $registry->searchProviders()));
        self::assertNull($registry->searchBackend());

        $migrations = new SearchMigrations();
        self::assertSame('SightPress\Core\Search\Migrations', $migrations->namespace());
        self::assertDirectoryExists($migrations->directory());
    }
}
