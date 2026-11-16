<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\User;

use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\User\TimezoneChoices;

#[CoversClass(TimezoneChoices::class)]
final class TimezoneChoicesTest extends TestCase
{
    public function testGroupsEveryTimeZoneByRegionWithReadablePlaces(): void
    {
        $groups = TimezoneChoices::grouped();

        self::assertSame('UTC', array_key_first($groups));
        self::assertSame(['UTC' => 'UTC (Coordinated Universal Time)'], $groups['UTC']);
        self::assertSame('Lisbon', $groups['Europe']['Europe/Lisbon']);
        self::assertSame('Sao Paulo', $groups['America']['America/Sao_Paulo']);
        self::assertSame('Argentina – Buenos Aires', $groups['America']['America/Argentina/Buenos_Aires']);
        self::assertSame('Tokyo', $groups['Asia']['Asia/Tokyo']);
        $all = array_merge(...array_values(array_map(array_keys(...), $groups)));
        self::assertCount(count(DateTimeZone::listIdentifiers()), $all);
    }

    public function testOnlyRealTimeZonesAreValid(): void
    {
        self::assertTrue(TimezoneChoices::isValid('America/Sao_Paulo'));
        self::assertFalse(TimezoneChoices::isValid('Mars/Olympus'));
        self::assertFalse(TimezoneChoices::isValid('europe/lisbon'));
    }
}
