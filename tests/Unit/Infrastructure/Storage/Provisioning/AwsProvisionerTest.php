<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Infrastructure\Storage\Provisioning;

use Aws\CloudFront\CloudFrontClient;
use Aws\S3\S3Client;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Storage\Provisioning\AwsProvisioner;
use SightPress\Core\Infrastructure\Storage\Provisioning\ProvisioningException;
use SightPress\Core\Infrastructure\Storage\Provisioning\ProvisioningPlan;
use SightPress\Core\Infrastructure\Storage\Provisioning\ProvisioningRequest;
use SightPress\Core\Infrastructure\Storage\Provisioning\ProvisioningResult;
use SightPress\Core\Template\Message;
use SightPress\Core\Tests\Support\FakeAws;

#[CoversClass(AwsProvisioner::class)]
#[CoversClass(ProvisioningException::class)]
#[CoversClass(ProvisioningPlan::class)]
#[CoversClass(ProvisioningRequest::class)]
#[CoversClass(ProvisioningResult::class)]
#[UsesClass(Message::class)]
final class AwsProvisionerTest extends TestCase
{
    private FakeAws $aws;

    private AwsProvisioner $provisioner;

    protected function setUp(): void
    {
        $this->aws = new FakeAws();
        $options = ['region' => 'eu-west-2', 'credentials' => ['key' => 'k', 'secret' => 's'], 'handler' => $this->aws];
        $this->provisioner = new AwsProvisioner(new S3Client(['version' => '2006-03-01'] + $options), new CloudFrontClient(['version' => '2020-05-31'] + $options));
        $this->aws->answers = [
            'HeadBucket' => ['error' => 'NotFound', 'status' => 404],
            'GetBucketCors' => ['error' => 'NoSuchCORSConfiguration', 'status' => 404],
            'GetBucketLifecycleConfiguration' => ['error' => 'NoSuchLifecycleConfiguration', 'status' => 404],
            'GetBucketPolicy' => ['error' => 'NoSuchBucketPolicy', 'status' => 404],
            'CreateOriginAccessControl' => ['OriginAccessControl' => ['Id' => 'oac-1']],
            'CreateCachePolicy' => ['CachePolicy' => ['Id' => 'cache-1']],
            'CreateDistribution' => ['Distribution' => ['ARN' => 'arn:aws:cloudfront::123456789012:distribution/D1', 'DomainName' => 'd1.cloudfront.net']],
        ];
    }

    private static function request(string $region = 'eu-west-2'): ProvisioningRequest
    {
        return new ProvisioningRequest('media-bucket', $region, 'sites/one', ['https://demo.example'], 'demo.example');
    }

    public function testPlanNamesWhatAndWhereAndChangesNothing(): void
    {
        $plan = $this->provisioner->plan(self::request());
        self::assertSame('eu-west-2', $plan->location);
        self::assertStringContainsString('media-bucket', (string) $plan->steps[0]);
        self::assertStringContainsString('international transfer', (string) $plan->transfer);
        self::assertSame([], $this->aws->calls);
    }

    public function testCreatesEverythingSafely(): void
    {
        $result = $this->provisioner->provision(self::request());

        self::assertSame(['bucket', 'CDN'], $result->created);
        self::assertSame([], $result->unchanged);
        self::assertSame('d1.cloudfront.net', $result->cdnHost);
        self::assertSame(['LocationConstraint' => 'eu-west-2'], FakeAws::dig($this->aws->last('CreateBucket'), 'CreateBucketConfiguration'));
        self::assertSame(['BlockPublicAcls' => true, 'IgnorePublicAcls' => true, 'BlockPublicPolicy' => true, 'RestrictPublicBuckets' => true], FakeAws::dig($this->aws->last('PutPublicAccessBlock'), 'PublicAccessBlockConfiguration'));

        $cors = FakeAws::dig($this->aws->last('PutBucketCors'), 'CORSConfiguration', 'CORSRules', 0);
        self::assertSame(['PUT'], FakeAws::dig($cors, 'AllowedMethods'));
        self::assertSame(['https://demo.example'], FakeAws::dig($cors, 'AllowedOrigins'));
        self::assertSame(['ETag'], FakeAws::dig($cors, 'ExposeHeaders'));

        $rules = FakeAws::dig($this->aws->last('PutBucketLifecycleConfiguration'), 'LifecycleConfiguration', 'Rules');
        self::assertSame(['DaysAfterInitiation' => 1], FakeAws::dig($rules, 0, 'AbortIncompleteMultipartUpload'));
        self::assertSame(['Prefix' => 'sites/one/'], FakeAws::dig($rules, 0, 'Filter'));
        self::assertSame(['Days' => 2], FakeAws::dig($rules, 1, 'Expiration'));
        self::assertSame(['Prefix' => 'sites/one/incoming/'], FakeAws::dig($rules, 1, 'Filter'));
    }

    public function testTheCdnCanNeverReadAPrivateFile(): void
    {
        $this->provisioner->provision(self::request());

        $policy = FakeAws::json(FakeAws::dig($this->aws->last('PutBucketPolicy'), 'Policy'));
        self::assertSame('arn:aws:s3:::media-bucket/sites/one/public/*', FakeAws::dig($policy, 'Statement', 0, 'Resource'));
        self::assertSame('s3:GetObject', FakeAws::dig($policy, 'Statement', 0, 'Action'));
        self::assertSame('arn:aws:cloudfront::123456789012:distribution/D1', FakeAws::dig($policy, 'Statement', 0, 'Condition', 'StringEquals', 'AWS:SourceArn'));

        $config = FakeAws::dig($this->aws->last('CreateDistribution'), 'DistributionConfig');
        self::assertSame('bucket', FakeAws::dig($config, 'DefaultCacheBehavior', 'TargetOriginId'));
        self::assertSame(['/media/*', '/themes/*', '/assets/*'], array_column(FakeAws::items(FakeAws::dig($config, 'CacheBehaviors', 'Items')), 'PathPattern'));
        self::assertSame(['site', 'site', 'site'], array_column(FakeAws::items(FakeAws::dig($config, 'CacheBehaviors', 'Items')), 'TargetOriginId'));
        self::assertSame(['GET', 'HEAD'], FakeAws::dig($config, 'CacheBehaviors', 'Items', 0, 'AllowedMethods', 'Items'));
        self::assertSame('https-only', FakeAws::dig($config, 'Origins', 'Items', 0, 'CustomOriginConfig', 'OriginProtocolPolicy'));

        $key = FakeAws::dig($this->aws->last('CreateCachePolicy'), 'CachePolicyConfig', 'ParametersInCacheKeyAndForwardedToOrigin');
        self::assertSame('all', FakeAws::dig($key, 'QueryStringsConfig', 'QueryStringBehavior'));
        self::assertSame('none', FakeAws::dig($key, 'CookiesConfig', 'CookieBehavior'));
    }

    public function testUsEastHasNoLocationConstraint(): void
    {
        $options = ['region' => 'us-east-1', 'credentials' => ['key' => 'k', 'secret' => 's'], 'handler' => $this->aws];
        new AwsProvisioner(new S3Client(['version' => '2006-03-01'] + $options), new CloudFrontClient(['version' => '2020-05-31'] + $options))->provision(self::request('us-east-1'));
        self::assertArrayNotHasKey('CreateBucketConfiguration', $this->aws->last('CreateBucket'));
    }

    public function testRunningAgainCreatesNothingAndKeepsOtherRules(): void
    {
        $reference = 'sightpress-' . substr(hash('sha256', 'media-bucket/sites/one'), 0, 32);
        $tag = substr(hash('sha256', 'sites/one'), 0, 32);
        $sid = AwsProvisioner::POLICY_SID . substr($tag, 0, 12);
        $this->aws->answers = [
            'GetBucketCors' => ['CORSRules' => [['ID' => 'theirs', 'AllowedMethods' => ['GET'], 'AllowedOrigins' => ['*']], ['ID' => AwsProvisioner::CORS_ID, 'AllowedMethods' => ['PUT'], 'AllowedOrigins' => ['https://old.example']]]],
            'GetBucketLifecycleConfiguration' => ['Rules' => [['ID' => 'theirs', 'Status' => 'Enabled', 'Filter' => ['Prefix' => 'logs/'], 'Expiration' => ['Days' => 9]], ['ID' => AwsProvisioner::ABORT_RULE . '-' . $tag, 'Status' => 'Enabled', 'Filter' => ['Prefix' => 'x/']]]],
            'GetBucketPolicy' => ['Policy' => json_encode(['Statement' => [['Sid' => 'theirs'], ['Sid' => $sid, 'Resource' => 'old']]])],
            'ListOriginAccessControls' => [
                ['OriginAccessControlList' => ['Items' => [['Name' => 'other', 'Id' => 'x']], 'NextMarker' => 'page-2']],
                ['OriginAccessControlList' => ['Items' => [['Name' => 'sightpress-' . substr(hash('sha256', 'media-bucket'), 0, 32), 'Id' => 'oac-1']]]],
            ],
            'ListCachePolicies' => ['CachePolicyList' => ['Items' => [['CachePolicy' => ['Id' => 'other', 'CachePolicyConfig' => ['Name' => 'other']]], ['CachePolicy' => ['Id' => 'cache-1', 'CachePolicyConfig' => ['Name' => 'sightpress-media']]]]]],
            'ListDistributions' => ['DistributionList' => ['Items' => [['Comment' => 'other'], ['Comment' => $reference, 'ARN' => 'arn:aws:cloudfront::1:distribution/D1', 'DomainName' => 'd1.cloudfront.net']]]],
        ];

        $result = $this->provisioner->provision(self::request());

        self::assertSame([], $result->created);
        self::assertSame(['bucket', 'CDN'], $result->unchanged);
        foreach (['CreateBucket', 'CreateOriginAccessControl', 'CreateCachePolicy', 'CreateDistribution'] as $operation) {
            self::assertNotContains($operation, $this->aws->names());
        }
        $cors = FakeAws::dig($this->aws->last('PutBucketCors'), 'CORSConfiguration', 'CORSRules');
        self::assertSame(['theirs', AwsProvisioner::CORS_ID], array_column(FakeAws::items($cors), 'ID'));
        self::assertSame(['https://demo.example'], FakeAws::dig($cors, 1, 'AllowedOrigins'));
        self::assertCount(3, FakeAws::items(FakeAws::dig($this->aws->last('PutBucketLifecycleConfiguration'), 'LifecycleConfiguration', 'Rules')));
        $statements = FakeAws::dig(FakeAws::json(FakeAws::dig($this->aws->last('PutBucketPolicy'), 'Policy')), 'Statement');
        self::assertSame(['theirs', $sid], array_column(FakeAws::items($statements), 'Sid'));
        self::assertSame('arn:aws:s3:::media-bucket/sites/one/public/*', FakeAws::dig($statements, 1, 'Resource'));
    }

    /**
     * @return iterable<string, array{0: string, 1: array<string, mixed>, 2: string}>
     */
    public static function failures(): iterable
    {
        yield 'bad key' => ['CreateBucket', ['error' => 'InvalidAccessKeyId', 'status' => 403], 'refused the access key'];
        yield 'expired' => ['ListDistributions', ['error' => 'ExpiredToken', 'status' => 403], 'refused the access key'];
        yield 'name taken on create' => ['CreateBucket', ['error' => 'BucketAlreadyExists', 'status' => 409], 'is taken'];
        yield 'name taken on head' => ['HeadBucket', ['error' => 'Forbidden', 'status' => 403], 'is taken'];
        yield 'other region' => ['HeadBucket', ['error' => 'PermanentRedirect', 'status' => 301], 'another region'];
        yield 'denied' => ['PutBucketCors', ['error' => 'AccessDenied', 'status' => 403], 'aren\'t allowed'];
        yield 'cors read' => ['GetBucketCors', ['error' => 'AccessDenied', 'status' => 403], 'aren\'t allowed'];
        yield 'policy read' => ['GetBucketPolicy', ['error' => 'AccessDenied', 'status' => 403], 'aren\'t allowed'];
        yield 'network' => ['PutPublicAccessBlock', ['status' => 500], 'can\'t be reached'];
        yield 'other' => ['CreateDistribution', ['error' => 'Too-Many!', 'status' => 400], '(TooMany)'];
    }

    /**
     * @param array<string, mixed> $answer
     */
    #[DataProvider('failures')]
    public function testFailuresArePlainAndNeverQuoteTheProvider(string $operation, array $answer, string $expected): void
    {
        $this->aws->answers[$operation] = $answer;
        try {
            $this->provisioner->provision(self::request());
            self::fail('It should have failed.');
        } catch (ProvisioningException $exception) {
            self::assertStringContainsString($expected, $exception->getMessage());
            self::assertStringNotContainsString('AKIA', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }
}
