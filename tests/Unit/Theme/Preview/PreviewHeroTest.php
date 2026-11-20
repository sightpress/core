<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Theme\Preview;

use DateTimeImmutable;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Module\CoreModules;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Core\Page\Contract\PublicPageRendering;
use SightPress\Core\Theme\Preview\PreviewHero;
use SightPress\Core\Theme\Preview\ThemePreviewModule;

#[CoversClass(PreviewHero::class)]
#[CoversClass(ThemePreviewModule::class)]
#[UsesNamespace('SightPress\Core')]
final class PreviewHeroTest extends TestCase
{
    private const array BUTTON = ['label' => 'Download', 'accessible_name' => 'Download "Harbour"', 'url' => 'https://acme.example/?a=1&b=2'];

    public function testPutsTheDownloadButtonInTheHeroOfTheHomePage(): void
    {
        $home = self::rendering(true);
        $other = self::rendering(false);
        $hero = new PreviewHero(self::BUTTON);
        $hero($home);
        $hero($other);

        self::assertSame(
            '<p class="hero__actions"><a class="button" href="https://acme.example/?a=1&amp;b=2" aria-label="Download &quot;Harbour&quot;">Download</a></p>',
            $home->variables()[PreviewHero::VARIABLE],
        );
        self::assertArrayNotHasKey(PreviewHero::VARIABLE, $other->variables());
    }

    public function testTheModuleRegistersTheHero(): void
    {
        $module = new ThemePreviewModule(self::BUTTON);
        $registry = ModuleRegistry::register(new ModuleLoader('0.2.0')->catalogue([
            ...CoreModules::candidates(),
            new ModuleCandidate($module, 'sightpress/core'),
        ])->resolve(new ModuleSelection()));

        self::assertSame('theme_preview', $module->manifest()->id);
        self::assertTrue($module->manifest()->required);
        $factory = $registry->services()[PreviewHero::class]->item->factory;
        self::assertNotNull($factory);
        self::assertEquals(new PreviewHero(self::BUTTON), $factory(new \DI\Container()));
        $listeners = array_values(array_filter($registry->listeners(), static fn ($s) => $s->module === 'theme_preview'));
        self::assertSame(PublicPageRendering::class, $listeners[0]->item->event);
        self::assertSame(PreviewHero::class, $listeners[0]->item->listener);
    }

    private static function rendering(bool $home): PublicPageRendering
    {
        return new PublicPageRendering(new ServerRequest('GET', '/'), 1, 'en-GB', '/', 'https://demo.example', 'Home', null, null, [], $home, true, new DateTimeImmutable());
    }
}
