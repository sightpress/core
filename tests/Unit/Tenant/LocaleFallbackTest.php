<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Tenant;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Tenant\LocaleCode;
use SightPress\Core\Tenant\LocaleFallback;

#[CoversClass(LocaleFallback::class)]
#[UsesClass(LocaleCode::class)]
final class LocaleFallbackTest extends TestCase
{
    private const array SITE = ['en-GB', 'pt', 'pt-PT', 'pt-AO', 'zh-Hant-TW', 'zh-Hans-CN', 'es'];

    /**
     * @return iterable<string, array{string, list<string>, list<string>}>
     */
    public static function chains(): iterable
    {
        yield 'exact, language, same-language region, default' => ['pt-BR', self::SITE, ['pt', 'pt-PT', 'pt-AO', 'en-GB']];
        yield 'exact first' => ['pt-PT', self::SITE, ['pt-PT', 'pt', 'pt-AO', 'en-GB']];
        yield 'written another way' => ['PT_br', self::SITE, ['pt', 'pt-PT', 'pt-AO', 'en-GB']];
        yield 'no language without region on the site' => ['pt-BR', ['en-GB', 'pt-PT'], ['pt-PT', 'en-GB']];
        yield 'Traditional Chinese stays traditional' => ['zh-Hant', self::SITE, ['zh-Hant-TW', 'en-GB']];
        yield 'another Traditional region' => ['zh-Hant-HK', self::SITE, ['zh-Hant-TW', 'en-GB']];
        yield 'Chinese without a script matches no script' => ['zh', self::SITE, ['en-GB']];
        yield 'unknown language' => ['tlh', self::SITE, ['en-GB']];
        yield 'not a language at all' => ['!!', self::SITE, ['en-GB']];
        yield 'empty' => ['', ['en-GB', 'en-US'], ['en-GB']];
        yield 'only a separator' => ['_', self::SITE, ['en-GB']];
        yield 'the default itself' => ['en-GB', self::SITE, ['en-GB']];
        yield 'another English' => ['en-US', self::SITE, ['en-GB']];
    }

    /**
     * @param list<string> $site
     * @param list<string> $chain
     */
    #[DataProvider('chains')]
    public function testFallsBackFromTheExactLocaleToTheDefault(string $requested, array $site, array $chain): void
    {
        self::assertSame($chain, LocaleFallback::chain($requested, $site, 'en-GB'));
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function headers(): iterable
    {
        yield 'best quality first' => ['de-DE,de;q=0.9,pt-BR;q=0.8,en;q=0.5', 'pt'];
        yield 'order breaks ties' => ['es;q=0.8, pt-PT;q=0.8', 'es'];
        yield 'quality zero is a no' => ['pt-PT;q=0, es;q=0.1', 'es'];
        yield 'English' => ['en-US,en;q=0.9', 'en-GB'];
        yield 'anything is no choice' => ['fr, *;q=0.1', null];
        yield 'no header' => ['', null];
        yield 'odd parameters are ignored' => ['zh-Hant-HK;level=1', 'zh-Hant-TW'];
    }

    #[DataProvider('headers')]
    public function testPicksASiteLanguageFromAcceptLanguage(string $header, ?string $locale): void
    {
        self::assertSame($locale, LocaleFallback::negotiate($header, self::SITE));
    }
}
