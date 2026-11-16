<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Template;

use DateTimeImmutable;
use InvalidArgumentException;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Template\DateFormatter;
use SightPress\Core\Template\Viewer;
use SightPress\Core\Template\ViewerPreferences;

#[CoversClass(DateFormatter::class)]
#[CoversClass(Viewer::class)]
#[CoversClass(ViewerPreferences::class)]
final class DateFormatterTest extends TestCase
{
    private DateTimeImmutable $utc;

    protected function setUp(): void
    {
        $this->utc = new DateTimeImmutable('2026-07-01 23:30:00 UTC');
    }

    public function testShowsAUtcDateInTheViewersTimeZoneAndLanguage(): void
    {
        $dates = new DateFormatter();

        self::assertSame('2 July 2026', $dates->date($this->utc, new Viewer('en-GB', 'Europe/London')));
        self::assertSame('1 July 2026', $dates->date($this->utc, new Viewer('en-GB', 'UTC')));
        self::assertSame('2 de julho de 2026', $dates->date($this->utc, new Viewer('pt-BR', 'Europe/Lisbon')));
        self::assertSame('2 Jul 2026, 00:30:00', $dates->dateTime($this->utc, new Viewer('en-GB', 'Europe/London')));
        self::assertSame('02/07/2026', $dates->date($this->utc, new Viewer('en-GB', 'Europe/London'), 'short'));
        self::assertSame('', $dates->dateTime(null, new Viewer('en-GB', 'UTC')));
    }

    public function testUtcIsShownInEachViewersOwnTimeZoneAcrossADaylightSavingChange(): void
    {
        $dates = new DateFormatter();
        $saoPaulo = new Viewer('pt-BR', 'America/Sao_Paulo');
        $tokyo = new Viewer('ja-JP', 'Asia/Tokyo');
        $london = new Viewer('en-GB', 'Europe/London');

        // São Paulo left summer time (UTC-2 → UTC-3) at midnight on 18 February 2018.
        self::assertMatchesRegularExpression('#^17/02/2018,? 23:30$#', $dates->dateTime(new \DateTimeImmutable('2018-02-18 01:30:00 UTC'), $saoPaulo, 'short'));
        self::assertMatchesRegularExpression('#^17/02/2018,? 23:30$#', $dates->dateTime(new \DateTimeImmutable('2018-02-18 02:30:00 UTC'), $saoPaulo, 'short'));
        // Tokyo has no summer time: always UTC+9.
        self::assertMatchesRegularExpression('#^2018/02/18,? 10:30$#', $dates->dateTime(new \DateTimeImmutable('2018-02-18 01:30:00 UTC'), $tokyo, 'short'));
        self::assertMatchesRegularExpression('#^2026/07/02,? 8:30$#', $dates->dateTime($this->utc, $tokyo, 'short'));
        // London moved its clocks forward at 01:00 UTC on 29 March 2026.
        self::assertMatchesRegularExpression('#^29/03/2026,? 00:59$#', $dates->dateTime(new \DateTimeImmutable('2026-03-29 00:59:00 UTC'), $london, 'short'));
        self::assertMatchesRegularExpression('#^29/03/2026,? 02:00$#', $dates->dateTime(new \DateTimeImmutable('2026-03-29 01:00:00 UTC'), $london, 'short'));
    }

    public function testAnUnknownStyleIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Date style "tiny" is not valid: use short, medium, long or full.');

        new DateFormatter()->date($this->utc, new Viewer('en-GB', 'UTC'), 'tiny');
    }

    public function testAnUnknownTimeZoneFailsClearly(): void
    {
        $this->expectException(\IntlException::class);
        $this->expectExceptionMessage('No such time zone: "Mars/Olympus"');

        new DateFormatter()->date($this->utc, new Viewer('en-GB', 'Mars/Olympus'));
    }

    public function testTheViewerComesFromTheRequestOrTheDefault(): void
    {
        $default = new Viewer('en-GB', 'UTC');
        $mine = new Viewer('cy-GB', 'Europe/London');
        $request = new ServerRequest('GET', '/');

        self::assertSame($default, Viewer::fromRequest($request, $default));
        self::assertSame($mine, Viewer::fromRequest($request->withAttribute(Viewer::class, $mine), $default));
    }

    public function testASignedInPersonsPreferencesReplaceTheDefaultsTheyChose(): void
    {
        $default = new Viewer('en-GB', 'UTC');
        $request = new ServerRequest('GET', '/');

        $tokyo = Viewer::fromRequest($request->withAttribute(ViewerPreferences::class, new ViewerPreferences(null, 'Asia/Tokyo')), $default);
        $welsh = Viewer::fromRequest($request->withAttribute(ViewerPreferences::class, new ViewerPreferences('cy-GB', '')), $default);

        self::assertEquals(new Viewer('en-GB', 'Asia/Tokyo'), $tokyo);
        self::assertEquals(new Viewer('cy-GB', 'UTC'), $welsh);
    }
}
