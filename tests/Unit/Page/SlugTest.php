<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Page\Slug;

#[CoversClass(Slug::class)]
final class SlugTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function slugs(): iterable
    {
        yield 'word' => ['about', true];
        yield 'hyphens and digits' => ['annual-report-2026', true];
        yield 'empty' => ['', false];
        yield 'upper case' => ['About', false];
        yield 'double hyphen' => ['a--b', false];
        yield 'leading hyphen' => ['-a', false];
        yield 'trailing hyphen' => ['a-', false];
        yield 'slash' => ['a/b', false];
        yield 'accent' => ['café', false];
        yield '60 characters' => [str_repeat('a', 60), true];
        yield '61 characters' => [str_repeat('a', 61), false];
    }

    #[DataProvider('slugs')]
    public function testChecksSlugs(string $slug, bool $valid): void
    {
        self::assertSame($valid, Slug::isValid($slug));
    }

    public function testMakesSlugsFromText(): void
    {
        self::assertSame('uber-uns-team', Slug::fromText('Über uns & Team!'));
        self::assertSame('relatorio-anual-2026', Slug::fromText('  Relatório Anual 2026 '));
        self::assertSame('page', Slug::fromText('!!!'));
        self::assertSame(str_repeat('a', 59), Slug::fromText(str_repeat('a', 59) . '-b'));
        self::assertTrue(Slug::isValid(Slug::fromText('Москва')));
    }

    public function testReservesSystemAddressesAndLocalePrefixesAtTheTop(): void
    {
        $locales = ['en-GB', 'pt-BR'];

        self::assertTrue(Slug::isReservedAtRoot('admin', $locales));
        self::assertTrue(Slug::isReservedAtRoot('login', $locales));
        self::assertTrue(Slug::isReservedAtRoot('pt-br', $locales));
        self::assertTrue(Slug::isReservedAtRoot('pt', $locales));
        self::assertTrue(Slug::isReservedAtRoot('en', $locales));
        self::assertFalse(Slug::isReservedAtRoot('about', $locales));
        self::assertFalse(Slug::isReservedAtRoot('fr', $locales));
    }
}
