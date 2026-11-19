<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Analytics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Analytics\QueryRedactor;

#[CoversClass(QueryRedactor::class)]
final class QueryRedactorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function queries(): iterable
    {
        yield 'a plain query, lower case' => ['  Opening   HOURS ', 'opening hours'];
        yield 'a year' => ['events 2026', 'events 2026'];
        yield 'a short number' => ['room 12345', 'room 12345'];
        yield 'nothing' => ["\t \n", null];
        yield 'an email address' => ['ada@example.org', null];
        yield 'an email address in a sentence' => ['contact Ada.Lovelace+cms@example.co.uk please', null];
        yield 'an email address written with at' => ['ada (at) example', null];
        yield 'an email address with a space' => ['ada @ example', null];
        yield 'a UK phone number' => ['+44 20 7946 0958', null];
        yield 'a Portuguese phone number' => ['912 345 678', null];
        yield 'a phone number with brackets and dashes' => ['(020) 7946-0958', null];
        yield 'a long number' => ['order 1234567', null];
        yield 'a card number' => ['4111-1111-1111-1111', null];
        yield 'Arabic-Indic digits' => ['٠١٢٣٤٥٦٧', null];
    }

    #[DataProvider('queries')]
    public function testDropsWhatLooksLikePersonalData(string $query, ?string $kept): void
    {
        self::assertSame($kept, new QueryRedactor()->redact($query));
    }

    public function testKeepsAtMostAHundredCharacters(): void
    {
        $kept = new QueryRedactor()->redact(str_repeat('á', 99) . ' b');

        self::assertSame(str_repeat('á', 99), $kept, 'cut, then trimmed');
        self::assertSame(QueryRedactor::LENGTH, mb_strlen((string) new QueryRedactor()->redact(str_repeat('abc', 50))));
    }
}
