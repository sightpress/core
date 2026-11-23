<?php

declare(strict_types=1);

namespace SightPress\Core\Infrastructure\Storage\Provisioning;

use Aws\CloudFront\CloudFrontClient;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use SightPress\Core\Template\Message;

/**
 * S3 bucket with public access blocked, browser-upload CORS, clean-up rules, and a CloudFront distribution. Only
 * `/media/*`, `/themes/*` and `/assets/*` are pulled from the site, which checks signatures (ADR-0051), so the CDN doesn't mirror
 * its pages. Everything else goes to the S3 origin through Origin Access Control, whose policy allows only the
 * request's public folder, so the CDN can never serve a private file.
 */
final readonly class AwsProvisioner implements CloudProvisioner
{
    public const string CORS_ID = 'sightpress-direct-upload';

    public const string ABORT_RULE = 'sightpress-abort-multipart';

    public const string INCOMING_RULE = 'sightpress-expire-incoming';

    public const string POLICY_SID = 'SightPressCloudFrontPublicRead';

    /** CloudFront's managed "CachingDisabled" is not used: this policy keeps the query in the key and drops cookies. */
    private const string CACHE_POLICY = 'sightpress-media';

    public function __construct(private S3Client $s3, private CloudFrontClient $cloudFront)
    {
    }

    public function plan(ProvisioningRequest $request): ProvisioningPlan
    {
        return new ProvisioningPlan('Amazon Web Services', $request->region, [
            new Message('A private S3 bucket “{bucket}” in {region}, with all public access blocked', ['bucket' => $request->bucket, 'region' => $request->region]),
            new Message('Browser uploads allowed only from: {origins}', ['origins' => implode(', ', $request->adminOrigins)]),
            new Message('Unfinished uploads removed after 1 day, and temporary uploads after 2 days'),
            new Message('A CloudFront CDN that fetches public files from {host}, so private files stay protected', ['host' => $request->siteHost]),
        ], new Message('Files stay in the location above. CloudFront keeps copies of public files in Europe and North America, which is an international transfer.'));
    }

    public function provision(ProvisioningRequest $request): ProvisioningResult
    {
        try {
            $isNewBucket = $this->bucket($request);
            $this->callS3('PutPublicAccessBlock', ['Bucket' => $request->bucket, 'PublicAccessBlockConfiguration' => [
                'BlockPublicAcls' => true, 'IgnorePublicAcls' => true, 'BlockPublicPolicy' => true, 'RestrictPublicBuckets' => true,
            ]]);
            $this->cors($request);
            $this->lifecycle($request);
            [$distribution, $isNewCdn] = $this->distribution($request, $this->originAccessControl($request), $this->cachePolicy());
            $this->bucketPolicy($request, self::text($distribution, 'ARN'));
        } catch (AwsException $exception) {
            throw new ProvisioningException(self::explain($exception, $request), previous: null);
        }
        $created = array_keys(array_filter(['bucket' => $isNewBucket, 'CDN' => $isNewCdn]));
        $unchanged = array_keys(array_filter(['bucket' => !$isNewBucket, 'CDN' => !$isNewCdn]));

        return new ProvisioningResult($created, $unchanged, self::text($distribution, 'DomainName'));
    }

    private function bucket(ProvisioningRequest $request): bool
    {
        try {
            $this->callS3('HeadBucket', ['Bucket' => $request->bucket]);

            return false;
        } catch (AwsException $exception) {
            if ($exception->getStatusCode() !== 404) {
                throw $exception;
            }
        }
        $arguments = ['Bucket' => $request->bucket];
        if ($request->region !== 'us-east-1') {
            $arguments['CreateBucketConfiguration'] = ['LocationConstraint' => $request->region];
        }
        $this->callS3('CreateBucket', $arguments);

        return true;
    }

    private function cors(ProvisioningRequest $request): void
    {
        $rules = array_values(array_filter($this->existing('GetBucketCors', $request, 'CORSRules', 'NoSuchCORSConfiguration'), static fn (array $rule): bool => ($rule['ID'] ?? null) !== self::CORS_ID));
        $rules[] = [
            'ID' => self::CORS_ID,
            'AllowedMethods' => ['PUT'],
            'AllowedOrigins' => $request->adminOrigins,
            'AllowedHeaders' => ['*'],
            'ExposeHeaders' => ['ETag'],
            'MaxAgeSeconds' => 3600,
        ];
        $this->callS3('PutBucketCors', ['Bucket' => $request->bucket, 'CORSConfiguration' => ['CORSRules' => $rules]]);
    }

    private function lifecycle(ProvisioningRequest $request): void
    {
        $ours = [self::ABORT_RULE . '-' . self::tag($request->prefix), self::INCOMING_RULE . '-' . self::tag($request->prefix)];
        $rules = array_values(array_filter($this->existing('GetBucketLifecycleConfiguration', $request, 'Rules', 'NoSuchLifecycleConfiguration'), static fn (array $rule): bool => !in_array($rule['ID'] ?? null, $ours, true)));
        $rules[] = ['ID' => $ours[0], 'Status' => 'Enabled', 'Filter' => ['Prefix' => $request->prefix . '/'], 'AbortIncompleteMultipartUpload' => ['DaysAfterInitiation' => 1]];
        $rules[] = ['ID' => $ours[1], 'Status' => 'Enabled', 'Filter' => ['Prefix' => $request->incomingPrefix()], 'Expiration' => ['Days' => 2]];
        $this->callS3('PutBucketLifecycleConfiguration', ['Bucket' => $request->bucket, 'LifecycleConfiguration' => ['Rules' => $rules]]);
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function existing(string $operation, ProvisioningRequest $request, string $key, string $missing): array
    {
        try {
            return self::arrays($this->callS3($operation, ['Bucket' => $request->bucket])[$key] ?? []);
        } catch (AwsException $exception) {
            if ($exception->getAwsErrorCode() !== $missing) {
                throw $exception;
            }

            return [];
        }
    }

    private function originAccessControl(ProvisioningRequest $request): string
    {
        $name = 'sightpress-' . self::tag($request->bucket);
        foreach ($this->pages('ListOriginAccessControls', 'OriginAccessControlList') as $item) {
            if (($item['Name'] ?? null) === $name) {
                return self::text($item, 'Id');
            }
        }
        $answer = $this->callCloudFront('CreateOriginAccessControl', ['OriginAccessControlConfig' => [
            'Name' => $name,
            'OriginAccessControlOriginType' => 's3',
            'SigningBehavior' => 'always',
            'SigningProtocol' => 'sigv4',
        ]]);

        return self::text(self::child($answer, 'OriginAccessControl'), 'Id');
    }

    private function cachePolicy(): string
    {
        foreach ($this->pages('ListCachePolicies', 'CachePolicyList', ['Type' => 'custom']) as $item) {
            $policy = self::child($item, 'CachePolicy');
            if ((self::child($policy, 'CachePolicyConfig')['Name'] ?? null) === self::CACHE_POLICY) {
                return self::text($policy, 'Id');
            }
        }
        $answer = $this->callCloudFront('CreateCachePolicy', ['CachePolicyConfig' => [
            'Name' => self::CACHE_POLICY,
            'MinTTL' => 0,
            'DefaultTTL' => 0,
            'MaxTTL' => 31536000,
            'ParametersInCacheKeyAndForwardedToOrigin' => [
                'EnableAcceptEncodingGzip' => true,
                'EnableAcceptEncodingBrotli' => true,
                'HeadersConfig' => ['HeaderBehavior' => 'none'],
                'CookiesConfig' => ['CookieBehavior' => 'none'],
                'QueryStringsConfig' => ['QueryStringBehavior' => 'all'],
            ],
        ]]);

        return self::text(self::child($answer, 'CachePolicy'), 'Id');
    }

    /**
     * @return array{0: array<array-key, mixed>, 1: bool}
     */
    private function distribution(ProvisioningRequest $request, string $control, string $cachePolicy): array
    {
        $reference = 'sightpress-' . self::tag($request->bucket . '/' . $request->prefix);
        foreach ($this->pages('ListDistributions', 'DistributionList') as $item) {
            if (($item['Comment'] ?? null) === $reference) {
                return [$item, false];
            }
        }
        $behaviour = static fn (string $origin): array => [
            'TargetOriginId' => $origin,
            'ViewerProtocolPolicy' => 'redirect-to-https',
            'CachePolicyId' => $cachePolicy,
            'Compress' => true,
            'AllowedMethods' => ['Quantity' => 2, 'Items' => ['GET', 'HEAD'], 'CachedMethods' => ['Quantity' => 2, 'Items' => ['GET', 'HEAD']]],
        ];
        $answer = $this->callCloudFront('CreateDistribution', ['DistributionConfig' => [
            'CallerReference' => $reference,
            'Comment' => $reference,
            'Enabled' => true,
            'PriceClass' => 'PriceClass_100',
            'HttpVersion' => 'http2and3',
            'Origins' => ['Quantity' => 2, 'Items' => [
                ['Id' => 'site', 'DomainName' => $request->siteHost, 'CustomOriginConfig' => ['HTTPPort' => 80, 'HTTPSPort' => 443, 'OriginProtocolPolicy' => 'https-only', 'OriginSslProtocols' => ['Quantity' => 1, 'Items' => ['TLSv1.2']]]],
                ['Id' => 'bucket', 'DomainName' => sprintf('%s.s3.%s.amazonaws.com', $request->bucket, $request->region), 'OriginAccessControlId' => $control, 'S3OriginConfig' => ['OriginAccessIdentity' => '']],
            ]],
            'DefaultCacheBehavior' => $behaviour('bucket'),
            'CacheBehaviors' => ['Quantity' => 3, 'Items' => [
                ['PathPattern' => '/media/*'] + $behaviour('site'),
                ['PathPattern' => '/themes/*'] + $behaviour('site'),
                ['PathPattern' => '/assets/*'] + $behaviour('site'),
            ]],
        ]]);

        return [self::child($answer, 'Distribution'), true];
    }

    /**
     * Every item of a CloudFront list, across its pages.
     *
     * @param array<string, string> $arguments
     *
     * @return list<array<array-key, mixed>>
     */
    private function pages(string $operation, string $list, array $arguments = []): array
    {
        $items = [];
        do {
            $page = self::child($this->callCloudFront($operation, $arguments), $list);
            $items = [...$items, ...self::arrays($page['Items'] ?? [])];
            $marker = $page['NextMarker'] ?? null;
            $arguments['Marker'] = is_string($marker) ? $marker : '';
        } while ($arguments['Marker'] !== '');

        return $items;
    }

    private function bucketPolicy(ProvisioningRequest $request, string $distributionArn): void
    {
        $statements = [];
        try {
            $policy = $this->callS3('GetBucketPolicy', ['Bucket' => $request->bucket])['Policy'] ?? '';
            $current = json_decode(is_string($policy) ? $policy : '', true);
            $statements = is_array($current) && is_array($current['Statement'] ?? null) ? $current['Statement'] : [];
        } catch (AwsException $exception) {
            if ($exception->getAwsErrorCode() !== 'NoSuchBucketPolicy') {
                throw $exception;
            }
        }
        $sid = self::POLICY_SID . substr(self::tag($request->prefix), 0, 12);
        $statements = array_values(array_filter($statements, static fn (mixed $statement): bool => !is_array($statement) || ($statement['Sid'] ?? null) !== $sid));
        $statements[] = [
            'Sid' => $sid,
            'Effect' => 'Allow',
            'Principal' => ['Service' => 'cloudfront.amazonaws.com'],
            'Action' => 's3:GetObject',
            'Resource' => sprintf('arn:aws:s3:::%s/%s*', $request->bucket, $request->publicPrefix()),
            'Condition' => ['StringEquals' => ['AWS:SourceArn' => $distributionArn]],
        ];
        $this->callS3('PutBucketPolicy', ['Bucket' => $request->bucket, 'Policy' => json_encode(['Version' => '2012-10-17', 'Statement' => $statements], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<array-key, mixed>
     */
    private function callS3(string $operation, array $arguments): array
    {
        return $this->s3->execute($this->s3->getCommand($operation, $arguments))->toArray();
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<array-key, mixed>
     */
    private function callCloudFront(string $operation, array $arguments): array
    {
        return $this->cloudFront->execute($this->cloudFront->getCommand($operation, $arguments))->toArray();
    }

    /**
     * A short, stable name part: rule ids and references have length and character limits a prefix could break.
     */
    private static function tag(string $value): string
    {
        return substr(hash('sha256', $value), 0, 32);
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private static function child(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        return is_array($value) ? $value : [];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function text(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private static function arrays(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_array(...))) : [];
    }

    private static function explain(AwsException $exception, ProvisioningRequest $request): string
    {
        $code = (string) $exception->getAwsErrorCode();

        return match (true) {
            in_array($code, ['InvalidAccessKeyId', 'SignatureDoesNotMatch', 'InvalidClientTokenId', 'ExpiredToken'], true) => 'AWS refused the access key or secret key. Check both and try again.',
            $code === 'BucketAlreadyExists', $exception->getStatusCode() === 403 && $exception->getCommand()->getName() === 'HeadBucket' => sprintf('The bucket name "%s" is taken, or these keys can\'t use it. Choose another with --bucket.', $request->bucket),
            $exception->getStatusCode() === 301, $code === 'PermanentRedirect' => sprintf('The bucket "%s" is in another region. Choose that region with --region.', $request->bucket),
            in_array($code, ['AccessDenied', 'AccessDeniedException'], true) => 'These AWS keys aren\'t allowed to do this. Give them the permissions listed in the storage guide.',
            $code === '' => 'AWS can\'t be reached. Check the network and try again.',
            default => sprintf('AWS refused the request (%s).', preg_replace('/[^A-Za-z0-9]/', '', $code)),
        };
    }
}
