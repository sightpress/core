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
use SightPress\Module\Setting\Setting;
use SightPress\Module\Setting\SettingType;

/**
 * Search engines: the `<title>`, meta description, canonical address, `hreflang`, robots rules and schema.org nodes
 * of every public page ({@see SeoHead}), `sitemap.xml` and `robots.txt` ({@see SeoFiles}, cached until a page
 * changes), `llms.txt` and `llms-full.txt` for AI readers, the AI crawler policy (setting `seo.ai_crawlers`, D11)
 * and `site:seo:check`. Always on.
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
        foreach ([RobotsPolicy::class, SeoHead::class, SeoFileHandler::class, SeoCachePurger::class, AiCrawlerPolicy::class] as $class) {
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
        $registrar->routes()->add(new Route('seo.llms', SeoFileHandler::LLMS, SeoFileHandler::class, ['GET', 'HEAD']));
        $registrar->routes()->add(new Route('seo.llms_full', SeoFileHandler::LLMS_FULL, SeoFileHandler::class, ['GET', 'HEAD']));
        $registrar->settings()->add(new Setting(
            AiCrawlerPolicy::SETTING,
            'AI crawlers',
            SettingType::Choice,
            AiCrawlerPolicy::ALLOW_ALL,
            'Whether AI services may read the public pages of this site: to answer people\'s questions with a link back (AI search and assistants), and to train their models.',
            AiCrawlerPolicy::CHOICES,
        ));
    }
}
