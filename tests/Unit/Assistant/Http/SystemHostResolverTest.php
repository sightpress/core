<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Assistant\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Assistant\Http\SystemHostResolver;

#[CoversClass(SystemHostResolver::class)]
final class SystemHostResolverTest extends TestCase
{
    public function testResolvesNamesAndKeepsAddresses(): void
    {
        $resolver = new SystemHostResolver();

        self::assertSame(['93.184.215.14'], $resolver->addresses('93.184.215.14'));
        self::assertSame(['::1'], $resolver->addresses('[::1]'));
        self::assertContains('127.0.0.1', $resolver->addresses('localhost'));
        self::assertSame([], $resolver->addresses('no-such-host.invalid'));
    }
}
