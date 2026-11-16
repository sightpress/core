<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Template;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Template\TextDirection;

#[CoversClass(TextDirection::class)]
final class TextDirectionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function locales(): iterable
    {
        yield 'British English' => ['en-GB', 'ltr'];
        yield 'Portuguese' => ['pt_PT', 'ltr'];
        yield 'Arabic' => ['ar', 'rtl'];
        yield 'Hebrew' => ['he-IL', 'rtl'];
        yield 'Persian' => ['fa-IR', 'rtl'];
        yield 'Urdu' => ['ur', 'rtl'];
        yield 'Kurdish in Latin script' => ['ku-Latn', 'ltr'];
        yield 'Azerbaijani in Arabic script' => ['az-Arab', 'rtl'];
        yield 'Japanese' => ['ja-JP', 'ltr'];
        yield 'empty' => ['', 'ltr'];
    }

    #[DataProvider('locales')]
    public function testRightToLeftScriptsAndLanguagesAreRtl(string $locale, string $direction): void
    {
        self::assertSame($direction, TextDirection::of($locale));
    }
}
