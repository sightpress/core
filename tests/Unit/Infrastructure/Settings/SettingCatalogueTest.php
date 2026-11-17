<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Infrastructure\Settings;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Settings\SettingCatalogue;
use SightPress\Core\Module\CoreModules;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Module\Setting\Setting;
use SightPress\Module\Setting\SettingType;

#[CoversClass(SettingCatalogue::class)]
#[UsesNamespace('SightPress\Core')]
final class SettingCatalogueTest extends TestCase
{
    public function testListsTheSettingsOfEveryModuleWithItsModule(): void
    {
        $catalogue = SettingCatalogue::fromRegistry(ModuleRegistry::register(
            new ModuleLoader('0.2.0')->catalogue(CoreModules::candidates())->resolve(new ModuleSelection()),
        ));

        $keys = array_map(static fn (array $entry): string => $entry['module'] . ':' . $entry['setting']->key, $catalogue->all());
        self::assertContains('site:site.contact_email', $keys);
        self::assertContains('seo:seo.ai_crawlers', $keys);
        self::assertSame('AI crawlers', $catalogue->get('seo.ai_crawlers')?->label);
        self::assertNull($catalogue->get('nope.nothing'));
    }

    public function testGivesTheStoredValueOrTheDefaultAsText(): void
    {
        self::assertSame('x', SettingCatalogue::stored(new Setting('a.b', 'A', SettingType::Text, 'd'), 'x'));
        self::assertSame('d', SettingCatalogue::stored(new Setting('a.b', 'A', SettingType::Text, 'd'), null));
        self::assertSame('', SettingCatalogue::stored(new Setting('a.b', 'A', SettingType::Text), null));
        self::assertSame('0', SettingCatalogue::stored(new Setting('a.b', 'A', SettingType::Boolean), null));
        self::assertSame('1', SettingCatalogue::stored(new Setting('a.b', 'A', SettingType::Boolean, true), null));
        self::assertSame('5', SettingCatalogue::stored(new Setting('a.b', 'A', SettingType::Integer, 5), null));
    }
}
