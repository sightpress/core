<?php

declare(strict_types=1);

namespace SightPress\Core\Seo;

use SightPress\Module\Event\MediaChanged;
use SightPress\Module\Event\MediaDeleted;
use SightPress\Module\Event\PageCreated;
use SightPress\Module\Event\PageMoved;
use SightPress\Module\Event\PagePurged;
use SightPress\Module\Event\PageSaved;
use SightPress\Module\Event\PageTransitioned;
use SightPress\Module\Event\TranslationPublished;
use SightPress\Module\Event\SettingsChanged;
use SightPress\Module\Event\SiteModuleChanged;
use SightPress\Core\Page\Contract\PublicPageRendering;
use SightPress\Core\Seo\Command\CheckSeoCommand;
use SightPress\Core\Seo\Contract\SiteVerification;
use SightPress\Core\Seo\Http\IndexNowKeyHandler;
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
        foreach ([RobotsPolicy::class, SeoHead::class, SeoFileHandler::class, SeoCachePurger::class, AiCrawlerPolicy::class, SearchConsoles::class, IndexNow::class, IndexNowKeyHandler::class] as $class) {
            $services->autowire($class);
        }
        $services->autowire(SeoFiles::class, public: true);

        $subscribers = $registrar->subscribers();
        $subscribers->listen(PublicPageRendering::class, SeoHead::class, SeoHead::PRIORITY);
        foreach ([PageCreated::class, PageSaved::class, PageMoved::class, PageTransitioned::class, TranslationPublished::class, PagePurged::class, SettingsChanged::class, MediaChanged::class, MediaDeleted::class, SiteModuleChanged::class] as $event) {
            $subscribers->listen($event, SeoCachePurger::class);
        }
        $subscribers->listen(PageTransitioned::class, IndexNow::class);
        $registrar->commands()->add(CheckSeoCommand::class);
        // Core's <head> partial every theme's shell includes (theme-interface CorePartial::Head).
        $registrar->views()->add(self::ID, dirname(__DIR__, 2) . '/resources/views/seo');
        $registrar->routes()->add(new Route('seo.sitemap', SeoFileHandler::SITEMAP, SeoFileHandler::class, ['GET', 'HEAD']));
        $registrar->routes()->add(new Route('seo.sitemap_locale', SeoFileHandler::LOCALE_SITEMAP, SeoFileHandler::class, ['GET', 'HEAD']));
        $registrar->routes()->add(new Route('seo.robots', SeoFileHandler::ROBOTS, SeoFileHandler::class, ['GET', 'HEAD']));
        $registrar->routes()->add(new Route('seo.llms', SeoFileHandler::LLMS, SeoFileHandler::class, ['GET', 'HEAD']));
        $registrar->routes()->add(new Route('seo.llms_full', SeoFileHandler::LLMS_FULL, SeoFileHandler::class, ['GET', 'HEAD']));
        $registrar->routes()->add(new Route('seo.indexnow_key', IndexNow::PATH, IndexNowKeyHandler::class, ['GET', 'HEAD']));
        $registrar->settings()->add(new Setting(
            AiCrawlerPolicy::SETTING,
            'AI crawlers',
            SettingType::Choice,
            AiCrawlerPolicy::ALLOW_ALL,
            'Whether AI services may read the public pages of this site: to answer people\'s questions with a link back (AI search and assistants), and to train their models.',
            AiCrawlerPolicy::CHOICES,
        ));
        $settings = $registrar->settings();
        $settings->add(new Setting(array_keys(SiteVerification::CODES)[0], 'Google Search Console verification code', SettingType::Text, help: 'From Google Search Console, when you add the site there with the "HTML tag" method: only the code in content="…".'));
        $settings->add(new Setting(array_keys(SiteVerification::CODES)[1], 'Bing Webmaster Tools verification code', SettingType::Text, help: 'From Bing Webmaster Tools, with the "meta tag" method: only the code in content="…".'));
        $settings->add(new Setting(array_keys(SiteVerification::CODES)[2], 'Yandex Webmaster verification code', SettingType::Text, help: 'From Yandex Webmaster, with the "meta tag" method: only the code in content="…".'));
        $settings->add(new Setting(IndexNow::SETTING, 'Tell search engines at once when a page is published or unpublished (IndexNow)', SettingType::Boolean, false, 'Off by default. When it is on, the site contacts the search engines that use IndexNow (such as Bing and Yandex) each time a page is published or unpublished.'));
    }
}
