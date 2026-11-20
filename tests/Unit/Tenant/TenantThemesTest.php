<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Tenant;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Tenant\TenantConfigException;
use SightPress\Core\Tenant\TenantThemes;

#[CoversClass(TenantThemes::class)]
#[UsesClass(TenantConfigException::class)]
final class TenantThemesTest extends TestCase
{
    public function testByDefaultEveryInstalledThemeIsAllowedAndNewSitesGetTheDefaultTheme(): void
    {
        $themes = TenantThemes::fromArray([]);

        self::assertNull($themes->allowed);
        self::assertSame('default', $themes->default);
        self::assertTrue($themes->allows('acme-harbour'));
    }

    public function testReadsTheAllowedThemesAndTheDefault(): void
    {
        $themes = TenantThemes::fromArray(['allowed' => ['default', 'acme-harbour', 'default'], 'default' => 'acme-harbour']);

        self::assertSame(['default', 'acme-harbour'], $themes->allowed);
        self::assertSame('acme-harbour', $themes->default);
        self::assertTrue($themes->allows('default'));
        self::assertFalse($themes->allows('acme-plain'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalid(): iterable
    {
        yield 'default not a theme id' => [['default' => 'My Theme'], 'Tenant setting "themes.default" must be a theme id, for example "default".'];
        yield 'allowed not a list' => [['allowed' => 'default'], 'Tenant setting "themes.allowed" must be a list of theme ids'];
        yield 'allowed with a bad id' => [['allowed' => ['default', 3]], 'Tenant setting "themes.allowed" must be a list of theme ids'];
        yield 'default not allowed' => [['allowed' => ['acme-harbour']], 'Tenant setting "themes.default" ("default") must be one of "themes.allowed" ("acme-harbour").'];
        yield 'nothing allowed' => [['allowed' => []], 'Tenant setting "themes.default" ("default") must be one of "themes.allowed" (which is empty).'];
    }

    /**
     * @param array<string, mixed> $section
     */
    #[DataProvider('invalid')]
    public function testRefusesSettingsThatDontWork(array $section, string $message): void
    {
        $this->expectException(TenantConfigException::class);
        $this->expectExceptionMessage($message);
        TenantThemes::fromArray($section);
    }
}
