<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Theme;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use SightPress\Core\Site\Contract\SiteSettings;
use SightPress\Core\Template\Message;
use SightPress\Core\Template\TemplatePaths;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Tests\Support\ArraySiteSettings;
use SightPress\Core\Tests\Support\Theme\HarbourTheme;
use SightPress\Core\Theme\SiteTheme;
use SightPress\Core\Theme\ThemeRegistry;
use Stringable;

#[CoversClass(SiteTheme::class)]
#[UsesClass(ThemeRegistry::class)]
#[UsesClass(Message::class)]
#[UsesClass(TemplatePaths::class)]
#[UsesNamespace('SightPress\Core\Tenant')]
final class SiteThemeTest extends TestCase
{
    /** @var list<string> */
    private array $warnings = [];

    public function testASiteWithoutAChoiceGetsTheTenantsDefault(): void
    {
        $theme = $this->theme([], ['themes' => ['default' => 'acme-harbour']]);

        self::assertSame('acme-harbour', $theme->chosen());
        self::assertSame('acme-harbour', $theme->theme()->manifest()->id);
        self::assertNull($theme->problem());
        self::assertSame([new HarbourTheme()->paths()->root, TemplatePaths::defaultTheme()], $theme->templatePaths()->themes);
        self::assertSame([], $this->warnings);
    }

    public function testTheAssetVersionChangesWithTheTheme(): void
    {
        $harbour = $this->theme([SiteTheme::SETTING => 'acme-harbour'])->assetVersion('abc');
        $default = $this->theme([])->assetVersion('abc');

        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $harbour);
        self::assertNotSame($harbour, $default);
        self::assertNotSame($default, $this->theme([])->assetVersion('def'));
        self::assertSame($default, $this->theme([])->assetVersion('abc'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $this->theme([])->assetVersion(null));
    }

    public function testTheThemesManifestDecidesTheLayouts(): void
    {
        $theme = $this->theme([SiteTheme::SETTING => 'acme-harbour']);

        self::assertTrue($theme->provides('sidebar'));
        self::assertFalse($theme->provides('landing'));
        self::assertSame('layouts/sidebar.html.twig', $theme->template('sidebar'));
        self::assertSame('layouts/default.html.twig', $theme->template('landing'));
    }

    public function testTheSitesOwnChoiceWins(): void
    {
        $theme = $this->theme([SiteTheme::SETTING => 'default'], ['themes' => ['default' => 'acme-harbour']]);

        self::assertSame('default', $theme->theme()->manifest()->id);
        self::assertSame([TemplatePaths::defaultTheme()], $theme->templatePaths()->themes);
    }

    public function testAMissingThemeFallsBackToTheDefaultThemeAndSaysWhy(): void
    {
        $theme = $this->theme([SiteTheme::SETTING => 'acme-gone']);

        self::assertSame('default', $theme->theme()->manifest()->id);
        self::assertSame('The theme acme-gone is not installed on the server any more.', (string) $theme->problem());
        self::assertSame(['The site falls back to the default theme: {reason}'], $this->warnings);
    }

    public function testAThemeTheTenantNoLongerAllowsFallsBack(): void
    {
        $theme = $this->theme([SiteTheme::SETTING => 'acme-harbour'], ['themes' => ['allowed' => ['default']]]);

        self::assertSame('default', $theme->theme()->manifest()->id);
        self::assertSame('The theme acme-harbour is not allowed on this server any more.', (string) $theme->problem());
    }

    public function testAThemeForAnotherContractFallsBack(): void
    {
        $theme = $this->theme([SiteTheme::SETTING => 'acme-harbour'], [], '0.3.0.0');

        self::assertSame('default', $theme->theme()->manifest()->id);
        self::assertSame('The theme acme-harbour doesn’t work with this version of SightPress.', (string) $theme->problem());
    }

    public function testASiteWhoseDatabaseIsNotReadyGetsTheDefault(): void
    {
        $settings = new class () implements SiteSettings {
            public function get(string $key): ?string
            {
                throw new RuntimeException('no such table');
            }

            public function set(string $key, string $value): void
            {
            }
        };

        $theme = new SiteTheme(new ThemeRegistry([new HarbourTheme()]), self::config([]), $settings, $this->logger());
        self::assertSame('default', $theme->chosen());
        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $theme->assetVersion('abc'));
    }

    /**
     * @param array<string, string> $settings
     * @param array<string, mixed>  $tenant
     */
    private function theme(array $settings, array $tenant = [], ?string $contract = null): SiteTheme
    {
        return new SiteTheme(new ThemeRegistry([new HarbourTheme()], [], $contract), self::config($tenant), new ArraySiteSettings($settings), $this->logger());
    }

    /**
     * @param array<string, mixed> $tenant
     */
    private static function config(array $tenant): TenantConfig
    {
        return TenantConfig::fromArray($tenant, '/srv/app');
    }

    private function logger(): AbstractLogger
    {
        $warnings = &$this->warnings;

        return new class ($warnings) extends AbstractLogger {
            /** @var list<string> */
            public array $lines;

            /**
             * @param list<string> $lines
             */
            public function __construct(array &$lines)
            {
                $this->lines = &$lines;
            }

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->lines[] = (string) $message;
            }
        };
    }
}
