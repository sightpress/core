<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page\Revision;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Page\Revision\TextDiff;

#[CoversClass(TextDiff::class)]
final class TextDiffTest extends TestCase
{
    public function testMarksTheWordsThatWereRemovedAndAdded(): void
    {
        self::assertSame([
            ['same', 'The'],
            ['removed', 'quick'],
            ['added', 'slow'],
            ['same', 'brown fox'],
            ['added', 'jumps'],
        ], TextDiff::compare('The quick brown fox', 'The slow brown fox jumps'));
    }

    public function testFindsTheWordsBothHaveInTheMiddle(): void
    {
        self::assertSame([
            ['same', 'a'],
            ['removed', 'b'],
            ['same', 'c d'],
            ['added', 'x'],
            ['same', 'e'],
            ['removed', 'f'],
            ['same', 'g'],
        ], TextDiff::compare('a b c d e f g', 'a c d x e g'));
        self::assertSame([['added', 'new'], ['same', 'start']], TextDiff::compare('start', 'new start'));
        self::assertSame([['removed', 'x y'], ['added', 'z']], TextDiff::compare('x y', 'z'));
        self::assertSame([['removed', 'a b'], ['added', 'c'], ['same', 'z']], TextDiff::compare('a b z', 'c z'));
    }

    public function testTidiesWhitespaceAndHandlesEmptyTexts(): void
    {
        self::assertSame([['same', 'one two']], TextDiff::compare("one\n  two ", 'one two'));
        self::assertTrue(TextDiff::same("one\n two", ' one two'));
        self::assertFalse(TextDiff::same('one', 'two'));
        self::assertSame([['added', 'Hello there']], TextDiff::compare('', 'Hello there'));
        self::assertSame([['removed', 'Gone']], TextDiff::compare('Gone', '   '));
        self::assertSame([], TextDiff::compare('', ''));
    }

    public function testComparesVeryLongChangedTextsAsAWhole(): void
    {
        $old = implode(' ', array_map(static fn (int $n): string => 'o' . $n, range(1, 1001)));
        $new = implode(' ', array_map(static fn (int $n): string => 'n' . $n, range(1, 1000)));

        $parts = TextDiff::compare('same ' . $old . ' end', 'same ' . $new . ' end');

        self::assertSame(['same', 'removed', 'added', 'same'], array_column($parts, 0));
        self::assertSame($old, $parts[1][1]);
    }
}
