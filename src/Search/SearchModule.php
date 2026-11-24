<?php

declare(strict_types=1);

namespace SightPress\Core\Search;

use SightPress\Module\Event\MediaChanged;
use SightPress\Module\Event\MediaDeleted;
use SightPress\Module\Event\PageMoved;
use SightPress\Module\Event\PagePurged;
use SightPress\Module\Event\PageSaved;
use SightPress\Module\Event\PageTransitioned;
use SightPress\Core\Page\Contract\PublicPageRendering;
use SightPress\Module\Event\TranslationPublished;
use SightPress\Core\Search\Analysis\TextAnalyzers;
use SightPress\Core\Search\Command\ReindexSearchCommand;
use SightPress\Core\Search\Command\SearchCommand;
use SightPress\Core\Search\Command\SearchStatusCommand;
use SightPress\Core\Search\Contract\SearchPage;
use SightPress\Core\Search\Http\SearchFormListener;
use SightPress\Core\Search\Http\SearchHandler;
use SightPress\Module\Event\LocaleRemoved;
use SightPress\Core\Site\Contract\ScheduleDue;
use SightPress\Module\Event\SettingsChanged;
use SightPress\Module\Event\SiteModuleChanged;
use SightPress\Module\ModuleInterface;
use SightPress\Module\ModuleManifest;
use SightPress\Module\ModuleRegistrar;
use SightPress\Module\ModuleRequirement;
use SightPress\Module\Routing\Route;

/**
 * Site search (ADR-0011, D18, D22, D23): an index of the site's published pages, and of what other modules add
 * through `search()`, in the site database ({@see DatabaseSearchBackend}) or in the back-end a module puts in its
 * place; kept up to date as pages and media change ({@see SearchIndexer}); the search form in the header of every
 * public page and the results page ({@see SearchHandler}); `site:search:reindex`, `site:search:status` and
 * `site:search`. Optional, on by default.
 */
final class SearchModule implements ModuleInterface
{
    public const string ID = SearchPage::MODULE;

    /** @var list<class-string> */
    private const array CHANGES = [
        PageTransitioned::class,
        TranslationPublished::class,
        PageSaved::class,
        PageMoved::class,
        PagePurged::class,
        MediaChanged::class,
        MediaDeleted::class,
        SettingsChanged::class,
        LocaleRemoved::class,
        SiteModuleChanged::class,
    ];

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            self::ID,
            '0.1.0',
            [new ModuleRequirement('site', '^0.1'), new ModuleRequirement('page', '^0.1')],
            enabledByDefault: true,
        );
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $registrar->migrations()->add(new SearchMigrations());

        $services = $registrar->services();
        foreach ([TextAnalyzers::class, DatabaseSearchBackend::class, SearchIndexer::class, SearchReindexJob::class] as $class) {
            $services->autowire($class);
        }
        $services->autowire(SearchIndex::class, public: true);
        $services->autowire(SiteSearch::class, public: true);
        $services->autowire(SearchHandler::class);
        $services->autowire(SearchFormListener::class);
        $registrar->views()->add(self::ID, dirname(__DIR__, 2) . '/resources/views/search');
        $routes = $registrar->routes();
        $routes->add(new Route('search.results', SearchHandler::PATH, SearchHandler::class, ['GET', 'HEAD']));
        $routes->add(new Route('search.results_locale', SearchHandler::LOCALE_PATH, SearchHandler::class, ['GET', 'HEAD']));

        $subscribers = $registrar->subscribers();
        foreach (self::CHANGES as $event) {
            $subscribers->listen($event, SearchIndexer::class);
        }
        $subscribers->listen(ScheduleDue::class, SearchReindexJob::class);
        $subscribers->listen(PublicPageRendering::class, SearchFormListener::class);

        foreach ([ReindexSearchCommand::class, SearchStatusCommand::class, SearchCommand::class] as $command) {
            $registrar->commands()->add($command);
        }
    }
}
