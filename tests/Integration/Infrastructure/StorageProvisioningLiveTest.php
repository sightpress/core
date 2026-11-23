<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Infrastructure;

use ArrayObject;
use Aws\CloudFront\CloudFrontClient;
use Aws\CommandInterface;
use Aws\Result;
use Aws\S3\S3Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use SightPress\Core\Infrastructure\Storage\Provisioning\AwsProvisioner;
use SightPress\Core\Infrastructure\Storage\Provisioning\CloudflareProvisioner;
use SightPress\Core\Assistant\Http\EndpointGuard;
use SightPress\Core\Assistant\Http\SystemHostResolver;
use SightPress\Core\Infrastructure\Storage\Provisioning\ProvisioningRequest;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Tests\Support\FakeAws;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Provisioning against real services, only when asked:
 *
 * - MinIO (`SIGHTPRESS_TEST_S3_ENDPOINT`, `_KEY`, `_SECRET`): the bucket and the expiry rule are real. MinIO has no
 *   public access block, CORS or multipart-abort rule, so those calls are checked here and not sent.
 * - AWS or R2 (`SIGHTPRESS_LIVE_PROVIDER=aws|cloudflare`, run by the manual workflow): creates real resources in the
 *   test account, under `SIGHTPRESS_LIVE_BUCKET`.
 */
#[CoversNothing]
final class StorageProvisioningLiveTest extends TestCase
{
    private static function env(string $name): string
    {
        $value = getenv($name);

        return is_string($value) ? $value : '';
    }

    private static function request(string $bucket, string $region): ProvisioningRequest
    {
        return new ProvisioningRequest($bucket, $region, 'live/' . 'provisioning', ['https://live-test.example'], 'live-test.example');
    }

    public function testBucketAndExpiryRuleOnMinio(): void
    {
        $endpoint = self::env('SIGHTPRESS_TEST_S3_ENDPOINT');
        if ($endpoint === '') {
            self::markTestSkipped('Set SIGHTPRESS_TEST_S3_ENDPOINT to run against MinIO.');
        }
        $s3 = new S3Client([
            'version' => '2006-03-01',
            'region' => 'us-east-1',
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => self::env('SIGHTPRESS_TEST_S3_KEY'), 'secret' => self::env('SIGHTPRESS_TEST_S3_SECRET')],
        ]);
        /** @var ArrayObject<string, array<array-key, mixed>> $sent */
        $sent = new ArrayObject();
        $s3->getHandlerList()->appendInit(static fn (callable $next): callable => static function (CommandInterface $command, ?RequestInterface $request = null) use ($next, $sent): PromiseInterface {
            $name = $command->getName();
            if (in_array($name, ['PutPublicAccessBlock', 'PutBucketCors', 'GetBucketPolicy', 'PutBucketPolicy'], true)) {
                $sent[$name] = $command->toArray();

                return Create::promiseFor(new Result(['Policy' => '{"Statement":[]}']));
            }
            if ($name === 'PutBucketLifecycleConfiguration') {
                $sent[$name] = $command->toArray();
                $command['LifecycleConfiguration'] = ['Rules' => array_values(array_filter(FakeAws::items(FakeAws::dig($command->toArray(), 'LifecycleConfiguration', 'Rules')), static fn (mixed $rule): bool => is_array($rule) && isset($rule['Expiration'])))];
            }

            $promise = $next($command, $request);
            self::assertInstanceOf(PromiseInterface::class, $promise);

            return $promise;
        }, 'minio-unsupported');
        $cloudFront = new FakeAws();
        $cloudFront->answers = [
            'CreateOriginAccessControl' => ['OriginAccessControl' => ['Id' => 'o']],
            'CreateCachePolicy' => ['CachePolicy' => ['Id' => 'c']],
            'CreateDistribution' => ['Distribution' => ['ARN' => 'arn:aws:cloudfront::1:distribution/D', 'DomainName' => 'd.cloudfront.net']],
        ];
        $provisioner = new AwsProvisioner($s3, new CloudFrontClient(['version' => '2020-05-31', 'region' => 'us-east-1', 'credentials' => ['key' => 'k', 'secret' => 's'], 'handler' => $cloudFront]));
        $bucket = 'sp-provision-' . bin2hex(random_bytes(6));
        $request = self::request($bucket, 'us-east-1');

        try {
            self::assertContains('bucket', $provisioner->provision($request)->created);
            self::assertTrue($s3->doesBucketExistV2($bucket));
            $rules = $s3->getBucketLifecycleConfiguration(['Bucket' => $bucket])->toArray()['Rules'] ?? [];
            self::assertSame('live/provisioning/incoming/', FakeAws::dig($rules, 0, 'Filter', 'Prefix'));
            self::assertSame(2, FakeAws::dig($rules, 0, 'Expiration', 'Days'));
            self::assertCount(2, FakeAws::items(FakeAws::dig($sent->getArrayCopy(), 'PutBucketLifecycleConfiguration', 'LifecycleConfiguration', 'Rules')));
            self::assertSame(['ETag'], FakeAws::dig($sent->getArrayCopy(), 'PutBucketCors', 'CORSConfiguration', 'CORSRules', 0, 'ExposeHeaders'));

            $cloudFront->answers['ListDistributions'] = ['DistributionList' => ['Items' => [[
                'Comment' => FakeAws::dig($cloudFront->last('CreateDistribution'), 'DistributionConfig', 'Comment'),
                'ARN' => 'arn:aws:cloudfront::1:distribution/D',
                'DomainName' => 'd.cloudfront.net',
            ]]]];
            self::assertSame([], $provisioner->provision($request)->created);
            self::assertCount(1, FakeAws::items($s3->getBucketLifecycleConfiguration(['Bucket' => $bucket])['Rules']));
        } finally {
            $s3->deleteBucket(['Bucket' => $bucket]);
        }
    }

    public function testRealProviderTwice(): void
    {
        $provider = self::env('SIGHTPRESS_LIVE_PROVIDER');
        if ($provider === '') {
            self::markTestSkipped('Only the manual live workflow sets SIGHTPRESS_LIVE_PROVIDER.');
        }
        $bucket = self::env('SIGHTPRESS_LIVE_BUCKET');
        if ($provider === 'aws') {
            $options = ['credentials' => ['key' => self::env('SIGHTPRESS_AWS_ACCESS_KEY_ID'), 'secret' => self::env('SIGHTPRESS_AWS_SECRET_ACCESS_KEY')]];
            $region = 'eu-west-2';
            $provisioner = new AwsProvisioner(new S3Client(['version' => '2006-03-01', 'region' => $region] + $options), new CloudFrontClient(['version' => '2020-05-31', 'region' => 'us-east-1'] + $options));
        } else {
            $region = 'eu';
            $config = TenantConfig::fromArray([], '/tmp/project', ['APP_ENV' => 'production']);
            $provisioner = new CloudflareProvisioner(HttpClient::create(), new EndpointGuard($config, new SystemHostResolver()), self::env('SIGHTPRESS_CLOUDFLARE_ACCOUNT_ID'), self::env('SIGHTPRESS_CLOUDFLARE_API_TOKEN'));
        }
        $request = self::request($bucket, $region);
        $provisioner->provision($request);
        self::assertSame([], $provisioner->provision($request)->created);
    }
}
