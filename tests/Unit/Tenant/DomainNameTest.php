<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Tenant;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Tenant\DomainName;

#[CoversClass(DomainName::class)]
final class DomainNameTest extends TestCase
{
    public function testNormalisesCaseTrailingDotsAndInternationalNames(): void
    {
        self::assertSame('example.org', DomainName::normalise(' Example.ORG. '));
        self::assertSame('xn--bcher-kva.example', DomainName::normalise('BÜCHER.example'));
        self::assertSame('xn--bcher-kva.example', DomainName::normalise('xn--bcher-kva.example'));
        self::assertSame('', DomainName::normalise(''));
        self::assertSame('a..é', DomainName::normalise('a..É'), 'a name IDN can\'t convert is kept as it is');
    }

    public function testTheDomainOfAnAddressIsWhatFollowsTheLastAt(): void
    {
        self::assertSame('example.org', DomainName::ofEmail('"a@b"@Example.Org'));
        self::assertSame('xn--bcher-kva.example', DomainName::ofEmail('ana@bücher.example'));
        self::assertSame('', DomainName::ofEmail('no-at-sign'));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function names(): iterable
    {
        yield 'the same name' => ['example.org', 'example.org', true];
        yield 'a name under it' => ['www.example.org', 'example.org', true];
        yield 'deeper' => ['a.b.example.org', 'example.org', true];
        yield 'upper case' => ['WWW.Example.Org.', 'example.org', true];
        yield 'international' => ['shop.BÜCHER.example', 'xn--bcher-kva.example', true];
        yield 'a longer name ending the same' => ['evil-example.org', 'example.org', false];
        yield 'the name inside another' => ['example.org.evil.test', 'example.org', false];
        yield 'the parent of it' => ['org', 'example.org', false];
        yield 'no parent' => ['example.org', '', false];
    }

    #[DataProvider('names')]
    public function testANameIsWithinItselfAndTheNamesUnderIt(string $domain, string $parent, bool $within): void
    {
        self::assertSame($within, DomainName::isWithin($domain, $parent));
    }
}
