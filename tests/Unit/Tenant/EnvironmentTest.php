<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Tenant;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Tenant\Environment;
use SightPress\Core\Tenant\TenantConfigException;

#[CoversClass(Environment::class)]
final class EnvironmentTest extends TestCase
{
    /**
     * @return iterable<string, array{string|null, Environment}>
     */
    public static function values(): iterable
    {
        yield 'missing' => [null, Environment::Production];
        yield 'empty' => ['  ', Environment::Production];
        yield 'prod' => ['prod', Environment::Production];
        yield 'production' => ['Production', Environment::Production];
        yield 'staging' => ['staging', Environment::Staging];
        yield 'dev' => ['dev', Environment::Dev];
        yield 'test' => ['test', Environment::Test];
    }

    #[DataProvider('values')]
    public function testItReadsAppEnv(?string $value, Environment $expected): void
    {
        self::assertSame($expected, Environment::fromValue($value));
    }

    public function testAnUnknownValueIsRefused(): void
    {
        $this->expectException(TenantConfigException::class);
        $this->expectExceptionMessage('APP_ENV is not valid ("local"): use production, staging, dev or test.');

        Environment::fromValue('local');
    }

    public function testOnlyProductionIsProduction(): void
    {
        self::assertTrue(Environment::Production->isProduction());
        self::assertFalse(Environment::Staging->isProduction());
    }
}
