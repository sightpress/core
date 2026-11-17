<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Seo;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Module\CoreModules;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Core\Page\Contract\PageSaved;
use SightPress\Core\Page\Contract\PageTransitioned;
use SightPress\Core\Page\Contract\PublicPageRendering;
use SightPress\Core\Seo\SeoCachePurger;
use SightPress\Core\Seo\SeoFiles;
use SightPress\Core\Seo\IndexNow;
use SightPress\Core\Seo\SeoHead;
use SightPress\Core\Seo\SeoModule;

#[CoversClass(SeoModule::class)]
#[UsesNamespace('SightPress\Core')]
final class SeoModuleTest extends TestCase
{
    public function testIsRequiredAndRegistersItsHeadFilesCacheAndCheck(): void
    {
        $module = new SeoModule();
        $registry = ModuleRegistry::register(new ModuleLoader('0.2.0')->catalogue(CoreModules::candidates())->resolve(new ModuleSelection()));

        self::assertSame(['seo', true], [$module->manifest()->id, $module->manifest()->required]);
        self::assertSame(['site', 'block', 'page', 'branding'], array_map(static fn ($r) => $r->moduleId, $module->manifest()->requires));
        self::assertTrue($registry->services()[SeoFiles::class]->item->public);
        $listeners = array_values(array_filter($registry->listeners(), static fn ($l) => $l->module === 'seo'));
        self::assertSame([PublicPageRendering::class, SeoHead::class, 10], [$listeners[0]->item->event, $listeners[0]->item->listener, $listeners[0]->item->priority]);
        self::assertContains([PageSaved::class, SeoCachePurger::class], array_map(static fn ($l) => [$l->item->event, $l->item->listener], $listeners));
        self::assertCount(6, $listeners);
        self::assertContains([PageTransitioned::class, IndexNow::class], array_map(static fn ($l) => [$l->item->event, $l->item->listener], $listeners));
        $routes = array_values(array_filter($registry->routes(), static fn ($r) => $r->module === 'seo'));
        self::assertSame(['/sitemap.xml', '/robots.txt', '/llms.txt', '/llms-full.txt', '/indexnow-key.txt'], array_map(static fn ($r) => $r->item->path, $routes));
        $settings = array_values(array_filter($registry->settings(), static fn ($s) => $s->module === 'seo'));
        self::assertSame(['seo.ai_crawlers', 'allow_all'], [$settings[0]->item->key, $settings[0]->item->default]);
        self::assertSame(['allow_all', 'search_only', 'block_all'], array_keys($settings[0]->item->choices));
        self::assertSame(['seo.ai_crawlers', 'seo.verify_google', 'seo.verify_bing', 'seo.verify_yandex', 'seo.indexnow'], array_map(static fn ($s) => $s->item->key, $settings));
        self::assertFalse($settings[4]->item->default);
        self::assertCount(1, array_filter($registry->commands(), static fn ($c) => $c->module === 'seo'));
    }
}
