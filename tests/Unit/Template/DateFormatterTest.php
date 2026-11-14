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

#[CoversClass(DateFormatter::class)]
#[CoversClass(Viewer::class)]
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
}
