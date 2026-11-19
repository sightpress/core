<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Analytics\Report;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Analytics\Report\DateRange;
use SightPress\Core\Analytics\Report\ReportTable;

#[CoversClass(ReportTable::class)]
#[UsesClass(DateRange::class)]
final class ReportTableTest extends TestCase
{
    public function testWritesCsvThatSpreadsheetsWillNotRunAsFormulas(): void
    {
        $table = new ReportTable('not-found', new DateRange('2026-11-01', '2026-11-07'), ['path', 'requests', 'change'], [
            ['path' => '/a, "b"', 'requests' => 3, 'change' => -12.5],
            ['path' => '=HYPERLINK("https://evil.example")', 'requests' => 1, 'change' => null],
            ['path' => '@sum', 'requests' => -1],
            ['path' => "line\nbreak", 'requests' => 0, 'change' => 1.0],
        ]);

        self::assertSame(
            "path,requests,change\r\n"
            . "\"/a, \"\"b\"\"\",3,-12.5\r\n"
            . "\"'=HYPERLINK(\"\"https://evil.example\"\")\",1,\r\n"
            . "'@sum,-1,\r\n"
            . "\"line\nbreak\",0,1\r\n",
            $table->csv(),
        );
        self::assertStringStartsWith("Path,\"Requests (Europe/London)\",Change\r\n", $table->csv(['Path', 'Requests (Europe/London)', 'Change']));
        self::assertSame(['report' => 'not-found', 'from' => '2026-11-01', 'to' => '2026-11-07', 'rows' => $table->rows], $table->toArray());
    }
}
