<?php

declare(strict_types=1);

namespace SightPress\Core\Seo;

use SightPress\Core\Page\Contract\PageCreated;
use SightPress\Core\Page\Contract\PageMoved;
use SightPress\Core\Page\Contract\PageSaved;
use SightPress\Core\Page\Contract\PageTransitioned;
use SightPress\Core\Page\Contract\PublicPageRendering;
use SightPress\Core\Seo\Command\CheckSeoCommand;
use SightPress\Core\Seo\Http\SeoFileHandler;
use SightPress\Module\ModuleInterface;
use SightPress\Module\ModuleManifest;
use SightPress\Module\ModuleRegistrar;
use SightPress\Module\ModuleRequirement;
use SightPress\Module\Routing\Route;

/**
 * Search engines: the `<title>`, meta description, canonical address, `hreflang`, robots rules and schema.org nodes
 * of every public page ({@see SeoHead}), `sitemap.xml` and `robots.txt` ({@see SeoFiles}, cached until a page
 * changes), and `site:seo:check`. Always on.
 */
final class SeoModule implements ModuleInterface
{
    public const string ID = 'seo';

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            self::ID,
            '0.1.0',
            [new ModuleRequirement('site', '^0.1'), new ModuleRequirement('block', '^0.1'), new ModuleRequirement('page', '^0.1'), new ModuleRequirement('branding', '^0.1')],
            required: true,
        );
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $services = $registrar->services();
        foreach ([RobotsPolicy::class, SeoHead::class, SeoFileHandler::class, SeoCachePurger::class] as $class) {
            $services->autowire($class);
        }
        $services->autowire(SeoFiles::class, public: true);

        $subscribers = $registrar->subscribers();
        $subscribers->listen(PublicPageRendering::class, SeoHead::class, SeoHead::PRIORITY);
        foreach ([PageCreated::class, PageSaved::class, PageMoved::class, PageTransitioned::class] as $event) {
            $subscribers->listen($event, SeoCachePurger::class);
        }
        $registrar->commands()->add(CheckSeoCommand::class);
        $registrar->routes()->add(new Route('seo.sitemap', SeoFileHandler::SITEMAP, SeoFileHandler::class, ['GET', 'HEAD']));
        $registrar->routes()->add(new Route('seo.robots', SeoFileHandler::ROBOTS, SeoFileHandler::class, ['GET', 'HEAD']));
    }
}
