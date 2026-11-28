<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Seo\Privacy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SightPress\Core\Page\Contract\PageLinks;
use SightPress\Core\Seo\IndexNow;
use SightPress\Core\Seo\Privacy\IndexNowProcessors;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Tests\Support\ArraySiteSettings;
use SightPress\Core\Tests\Support\SiteFixture;
use Symfony\Component\HttpClient\MockHttpClient;

#[CoversClass(IndexNowProcessors::class)]
#[UsesNamespace('SightPress\Core')]
final class IndexNowProcessorsTest extends TestCase
{
    public function testDeclaresIndexNowOnlyWhileItIsOnInProduction(): void
    {
        $processors = static function (bool $on, string $environment): array {
            $config = TenantConfig::fromArray([], '/tmp/project', ['APP_ENV' => $environment]);
            $indexNow = new IndexNow(new ArraySiteSettings($on ? [IndexNow::SETTING => '1'] : []), SiteFixture::site(), self::createStub(PageLinks::class), new MockHttpClient(), $config, new NullLogger());

            return [...new IndexNowProcessors($indexNow, $config)->processors()];
        };

        self::assertSame([], $processors(false, 'production'));
        self::assertSame([], $processors(true, 'dev'));
        $declared = $processors(true, 'production');
        self::assertSame(['seo.indexnow', ['api.indexnow.org']], [$declared[0]->id, $declared[0]->hosts]);
    }
}
