<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page\Publish;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Page\Publish\Contrast;

#[CoversClass(Contrast::class)]
final class ContrastTest extends TestCase
{
    public function testMeasuresContrastLikeWcag(): void
    {
        self::assertEqualsWithDelta(21.0, Contrast::ratio('#000', '#ffffff'), 0.001);
        self::assertEqualsWithDelta(1.0, Contrast::ratio('#777777', '#777'), 0.001);
        self::assertEqualsWithDelta(4.48, Contrast::ratio('#777777', '#ffffff'), 0.01);
        self::assertEqualsWithDelta(Contrast::ratio('#0a57c2', '#ffffff'), Contrast::ratio('#ffffff', '#0A57C2'), 0.0001);
    }

    public function testNormalisesHexColours(): void
    {
        self::assertSame('#aabbcc', Contrast::normalise(' #ABC '));
        self::assertSame('#1a2b3c', Contrast::normalise('#1A2B3C'));
        self::assertNull(Contrast::normalise('red'));
        self::assertNull(Contrast::normalise('#12345'));
    }
}
