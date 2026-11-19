<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Analytics\Report;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Analytics\Report\Figure;

#[CoversClass(Figure::class)]
final class FigureTest extends TestCase
{
    /**
     * @return iterable<string, array{int|float, int|float, ?float}>
     */
    public static function changes(): iterable
    {
        yield 'up' => [1349, 1204, 12.0];
        yield 'down' => [50, 200, -75.0];
        yield 'flat' => [7, 7, 0.0];
        yield 'rounded to one decimal' => [2, 3, -33.3];
        yield 'from zero to something has no percentage' => [5, 0, null];
        yield 'nothing either time' => [0, 0, 0.0];
        yield 'down to nothing' => [0, 4, -100.0];
        yield 'views per visitor' => [1.5, 1.25, 20.0];
    }

    #[DataProvider('changes')]
    public function testTheChangeComparedWithThePreviousPeriod(int|float $current, int|float $previous, ?float $change): void
    {
        $figure = new Figure($current, $previous);

        self::assertSame($change, $figure->change());
        self::assertSame(['current' => $current, 'previous' => $previous, 'change' => $change], $figure->toArray());
    }
}
