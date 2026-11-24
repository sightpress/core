<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Infrastructure\Settings;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Settings\SettingCatalogue;
use SightPress\Core\Infrastructure\Settings\SiteSettingValues;
use SightPress\Core\Tests\Support\ArraySiteSettings;
use SightPress\Module\Exception\InvalidValueException;
use SightPress\Module\Setting\Setting;
use SightPress\Module\Setting\SettingType;

#[CoversClass(SiteSettingValues::class)]
#[UsesClass(SettingCatalogue::class)]
final class SiteSettingValuesTest extends TestCase
{
    public function testSavedValuesAreTypedAndDefaultsFillTheGaps(): void
    {
        $catalogue = new SettingCatalogue(array_map(static fn (Setting $setting): array => ['module' => 'acme.events', 'setting' => $setting], [
            new Setting('events.page_size', 'Events per page', SettingType::Integer, 20),
            new Setting('events.reminders', 'Send reminders', SettingType::Boolean),
            new Setting('events.intro', 'Introduction', SettingType::Text),
            new Setting('events.layout', 'Layout', SettingType::Choice, 'list', choices: ['list' => 'List', 'grid' => 'Grid']),
        ]));

        $saved = new SiteSettingValues($catalogue, new ArraySiteSettings(['events.page_size' => '12', 'events.reminders' => '1', 'events.layout' => 'grid']));
        self::assertSame([12, true, null, 'grid'], [$saved->get('events.page_size'), $saved->get('events.reminders'), $saved->get('events.intro'), $saved->get('events.layout')]);

        $defaults = new SiteSettingValues($catalogue, new ArraySiteSettings(['events.reminders' => '0']));
        self::assertSame([20, false, 'list'], [$defaults->get('events.page_size'), $defaults->get('events.reminders'), $defaults->get('events.layout')]);

        $this->expectException(InvalidValueException::class);
        $this->expectExceptionMessage('No module registered the setting "events.unknown".');
        $defaults->get('events.unknown');
    }
}
