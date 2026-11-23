<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Infrastructure\Storage\Provisioning;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Assistant\Http\EndpointGuard;
use SightPress\Core\Infrastructure\Storage\Provisioning\DefaultRegion;
use SightPress\Core\Infrastructure\Storage\Provisioning\LazyAwsProvisioner;
use SightPress\Core\Infrastructure\Storage\Provisioning\ProvisioningException;
use SightPress\Core\Infrastructure\Storage\Provisioning\ProvisioningPlan;
use SightPress\Core\Infrastructure\Storage\Provisioning\StorageProvisioning;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteS3Storage;
use SightPress\Core\Tenant\SiteStorage;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Tests\Support\Assistant\FixedResolver;
use SightPress\Core\Tests\Support\FakeAws;
use SightPress\Core\Tests\Support\SiteFixture;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(StorageProvisioning::class)]
#[CoversClass(DefaultRegion::class)]
#[CoversClass(LazyAwsProvisioner::class)]
#[CoversClass(ProvisioningPlan::class)]
#[UsesNamespace('SightPress\Core')]
final class StorageProvisioningTest extends TestCase
{
    private const array KEYS = [
        'SIGHTPRESS_AWS_ACCESS_KEY_ID' => 'AKIAOPERATOR',
        'SIGHTPRESS_AWS_SECRET_ACCESS_KEY' => 'operator-secret',
        'SIGHTPRESS_CLOUDFLARE_ACCOUNT_ID' => 'acc',
        'SIGHTPRESS_CLOUDFLARE_API_TOKEN' => 'token',
    ];

    private FakeAws $aws;

    protected function setUp(): void
    {
        $this->aws = new FakeAws();
    }

    /**
     * @param array<string, string> $environment
     * @param list<string>          $packages
     */
    private function service(array $environment = self::KEYS, array $packages = [\stdClass::class], ?MockHttpClient $http = null): StorageProvisioning
    {
        $config = TenantConfig::fromArray([], '/tmp/project', ['APP_ENV' => 'production']);

        return new StorageProvisioning(
            $config,
            $http ?? new MockHttpClient(),
            static fn (string $name): string|false => $environment[$name] ?? false,
            ['handler' => $this->aws],
            $packages,
            new EndpointGuard($config, new FixedResolver(['api.cloudflare.com' => ['104.16.132.229']])),
            0,
        );
    }

    private static function site(string $timezone = 'Europe/London', ?SiteS3Storage $s3 = null): Site
    {
        $site = SiteFixture::site();
        $site = $site->withDisplay(null, null, $timezone);

        return $s3 === null ? $site : $site->withStorage(new SiteStorage()->withS3($s3));
    }

    private static function s3(?string $endpoint, string $region = 'eu-west-1', string $bucket = 'existing-bucket'): SiteS3Storage
    {
        $box = SiteFixture::secretBox();

        return new SiteS3Storage($bucket, $region, $endpoint, 'custom/prefix', false, $box->encrypt('a'), $box->encrypt('b'));
    }

    /**
     * @return iterable<string, array{0: string, 1: string|null, 2: string|null}>
     */
    public static function regions(): iterable
    {
        yield 'UK' => ['Europe/London', 'eu-west-2', 'eu'];
        yield 'Jersey' => ['Europe/Jersey', 'eu-west-2', 'eu'];
        yield 'Portugal' => ['Europe/Lisbon', 'eu-central-1', 'eu'];
        yield 'Azores' => ['Atlantic/Azores', 'eu-central-1', 'eu'];
        yield 'Germany' => ['Europe/Berlin', 'eu-central-1', 'eu'];
        yield 'Switzerland' => ['Europe/Zurich', 'eu-central-1', 'eu'];
        yield 'Norway' => ['Europe/Oslo', 'eu-central-1', 'eu'];
        yield 'not EU' => ['Europe/Moscow', null, null];
        yield 'US' => ['America/New_York', null, null];
    }

    #[DataProvider('regions')]
    public function testDefaultRegion(string $timezone, ?string $aws, ?string $cloudflare): void
    {
        self::assertSame($aws, DefaultRegion::aws($timezone));
        self::assertSame($cloudflare, DefaultRegion::cloudflare($timezone));
    }

    public function testBuildsARequestFromTheSite(): void
    {
        $site = self::site();
        $request = $this->service()->request($site, 'aws');
        self::assertSame('eu-west-2', $request->region);
        self::assertSame('sites/' . $site->id, $request->prefix);
        self::assertSame(array_map(static fn (string $host): string => 'https://' . $host, $site->domains), $request->adminOrigins);
        self::assertStringStartsWith('sightpress-', $request->bucket);
        self::assertSame($request->prefix . '/incoming/', $request->incomingPrefix());
        self::assertSame($request->prefix . '/public/', $request->publicPrefix());
    }

    public function testReusesTheSitesBucketAndLocation(): void
    {
        $aws = $this->service()->request(self::site(s3: self::s3(null)), 'aws');
        self::assertSame(['existing-bucket', 'eu-west-1', 'custom/prefix'], [$aws->bucket, $aws->region, $aws->prefix]);
        $r2 = $this->service()->request(self::site(s3: self::s3('https://acc.eu.r2.cloudflarestorage.com', 'auto')), 'cloudflare');
        self::assertSame(['existing-bucket', 'eu'], [$r2->bucket, $r2->region]);
        $plain = $this->service()->request(self::site(s3: self::s3('https://acc.r2.cloudflarestorage.com', 'auto')), 'cloudflare');
        self::assertSame('default', $plain->region);
        $other = $this->service()->request(self::site(s3: self::s3('https://minio.example')), 'aws');
        self::assertStringStartsWith('sightpress-', $other->bucket);
        self::assertNotSame('existing-bucket', $this->service()->request(self::site(s3: self::s3(null)), 'cloudflare')->bucket);
    }

    /**
     * @return iterable<string, array{0: string, 1: string|null, 2: string|null, 3: string|null, 4: string}>
     */
    public static function refusals(): iterable
    {
        yield 'provider' => ['azure', null, null, null, 'Choose a provider'];
        yield 'domain on aws' => ['aws', null, null, 'files.example.org', '--domain'];
        yield 'bad domain' => ['cloudflare', null, null, 'not a host', '--domain'];
        yield 'bad bucket' => ['aws', null, 'Bad_Name', null, 'bucket name'];
        yield 'long bucket' => ['aws', null, str_repeat('a', 57), null, 'bucket name'];
        yield 'bad region' => ['aws', 'London', null, null, 'region is not valid'];
        yield 'bad jurisdiction' => ['cloudflare', 'uk', null, null, 'jurisdiction is not valid'];
    }

    #[DataProvider('refusals')]
    public function testRefusals(string $provider, ?string $region, ?string $bucket, ?string $domain, string $expected): void
    {
        $this->expectException(ProvisioningException::class);
        $this->expectExceptionMessage($expected);
        $this->service()->request(self::site(), $provider, $region, $bucket, $domain);
    }

    public function testNoSilentChoiceOutsideTheUkAndEu(): void
    {
        $this->expectExceptionMessage('--region');
        $this->service()->request(self::site('America/New_York'), 'aws');
    }

    public function testAnExistingBucketCantMove(): void
    {
        $this->expectExceptionMessage('can\'t be moved');
        $this->service()->request(self::site(s3: self::s3(null)), 'aws', 'eu-west-2');
    }

    public function testASiteWithoutADomainIsRefused(): void
    {
        $site = self::site();
        $bare = new Site($site->id, $site->slug, $site->name, $site->status, $site->ownerType, [], $site->databaseDsn, $site->storage, $site->defaultLocale, $site->locales, $site->defaultTimezone, $site->createdAt);
        $this->expectExceptionMessage('no domain');
        $this->service()->request($bare, 'aws');
    }

    public function testPlanNeedsNoKeysContactsNobodyAndNamesATransfer(): void
    {
        $service = $this->service([]);
        $site = self::site();
        self::assertCount(4, $service->plan($site, 'aws', $service->request($site, 'aws'))->steps);
        $abroad = $service->plan($site, 'aws', $service->request($site, 'aws', 'us-east-1'));
        self::assertStringContainsString('international transfer', (string) $abroad->steps[4]);
        self::assertStringContainsString('eu-west-2', (string) $abroad->steps[4]);
        self::assertCount(3, $service->plan($site, 'cloudflare', $service->request($site, 'cloudflare'))->steps);
        $free = self::site('America/New_York');
        self::assertCount(4, $service->plan($free, 'aws', $service->request($free, 'aws', 'us-east-1'))->steps);
        self::assertSame([], $this->aws->calls);
    }

    public function testCredentialsAndTheAdminSwitch(): void
    {
        self::assertFalse($this->service()->allowedInAdmin());
        self::assertTrue($this->service([StorageProvisioning::ADMIN_SWITCH => '1'])->allowedInAdmin());
        self::assertTrue($this->service()->hasCredentials('aws'));
        self::assertFalse($this->service(['SIGHTPRESS_AWS_ACCESS_KEY_ID' => 'only-one'])->hasCredentials('aws'));
        self::assertFalse($this->service(['SIGHTPRESS_AWS_ACCESS_KEY_ID' => '', 'SIGHTPRESS_AWS_SECRET_ACCESS_KEY' => 'x'])->hasCredentials('aws'));
        self::assertFalse($this->service()->hasCredentials('azure'));
        putenv('SIGHTPRESS_PROVISION_FROM_ADMIN=1');
        try {
            $real = new StorageProvisioning(TenantConfig::fromArray([], '/tmp/project', ['APP_ENV' => 'production']), new MockHttpClient());
            self::assertTrue($real->allowedInAdmin());
        } finally {
            putenv('SIGHTPRESS_PROVISION_FROM_ADMIN');
        }
    }

    public function testProvisionNeedsKeysAndNamesThem(): void
    {
        $service = $this->service([]);
        $this->expectExceptionMessage('SIGHTPRESS_AWS_ACCESS_KEY_ID and SIGHTPRESS_AWS_SECRET_ACCESS_KEY');
        $service->provision('aws', $service->request(self::site(), 'aws'));
    }

    public function testProvisionsWithAws(): void
    {
        $this->aws->answers = [
            'CreateOriginAccessControl' => ['OriginAccessControl' => ['Id' => 'o']],
            'CreateCachePolicy' => ['CachePolicy' => ['Id' => 'c']],
            'CreateDistribution' => ['Distribution' => ['ARN' => 'arn:aws:cloudfront::1:distribution/D', 'DomainName' => 'd.cloudfront.net']],
        ];
        $service = $this->service();
        self::assertSame('d.cloudfront.net', $service->provision('aws', $service->request(self::site(), 'aws'))->cdnHost);
    }

    public function testProvisionsWithCloudflare(): void
    {
        $service = $this->service(http: new MockHttpClient(static fn (): MockResponse => new MockResponse('{"result":{}}')));
        self::assertSame(['bucket'], $service->provision('cloudflare', $service->request(self::site(), 'cloudflare'))->unchanged);
    }

    public function testSaysWhatToInstallWithoutTheAwsPackage(): void
    {
        $service = $this->service(packages: ['Missing\\Package']);
        $this->expectExceptionMessage('Install league/flysystem-aws-s3-v3');
        $service->plan(self::site(), 'aws', $service->request(self::site(), 'aws'));
    }
}
