<?php

declare(strict_types=1);

namespace SightPress\Core\Infrastructure\Storage\Provisioning;

use Aws\CloudFront\CloudFrontClient;
use Aws\S3\S3Client;
use Closure;
use SightPress\Core\Assistant\Http\EndpointGuard;
use SightPress\Core\Assistant\Http\SystemHostResolver;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteS3Storage;
use SightPress\Core\Tenant\TenantConfig;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The one way to provision a site's storage, for the command and the admin screen. The provider keys come from the
 * server's environment, are used for this run only and are never saved: the site keeps its own narrower keys
 * (`site:storage:s3`). Provisioning doesn't switch the site to the new storage or CDN.
 */
final readonly class StorageProvisioning
{
    public const array PROVIDERS = ['aws', 'cloudflare'];

    public const string SETTING = 'storage.provisioned';

    public const string ADMIN_SWITCH = 'SIGHTPRESS_PROVISION_FROM_ADMIN';

    private const array CREDENTIALS = [
        'aws' => ['SIGHTPRESS_AWS_ACCESS_KEY_ID', 'SIGHTPRESS_AWS_SECRET_ACCESS_KEY'],
        'cloudflare' => ['SIGHTPRESS_CLOUDFLARE_ACCOUNT_ID', 'SIGHTPRESS_CLOUDFLARE_API_TOKEN'],
    ];

    private const array JURISDICTIONS = ['eu', 'us', 'default'];

    /**
     * @param (Closure(string): (string|false))|null $environment reads a variable (tests pass their own)
     * @param array<string, mixed>                   $awsOptions  added to the AWS clients' options (tests: a fake handler)
     * @param list<string>                           $packages    classes the optional AWS package provides
     */
    public function __construct(
        private TenantConfig $config,
        private HttpClientInterface $http,
        private ?Closure $environment = null,
        private array $awsOptions = [],
        private array $packages = [S3Client::class, CloudFrontClient::class],
        private ?EndpointGuard $guard = null,
        private int $retryDelayMs = 500,
    ) {
    }

    public function allowedInAdmin(): bool
    {
        return $this->variable(self::ADMIN_SWITCH) === '1';
    }

    public function hasCredentials(string $provider): bool
    {
        foreach (self::CREDENTIALS[$provider] ?? ['-'] as $name) {
            if ($this->variable($name) === null) {
                return false;
            }
        }

        return isset(self::CREDENTIALS[$provider]);
    }

    /**
     * @throws ProvisioningException when a choice isn't valid, or no location can be chosen safely
     */
    public function request(Site $site, string $provider, ?string $region = null, ?string $bucket = null, ?string $domain = null): ProvisioningRequest
    {
        if (!in_array($provider, self::PROVIDERS, true)) {
            throw new ProvisioningException('Choose a provider: aws or cloudflare.');
        }
        if ($site->domains === []) {
            throw new ProvisioningException('The site has no domain, so browser uploads and the CDN can\'t be set up.');
        }
        if ($domain !== null && ($provider !== 'cloudflare' || preg_match(Site::DOMAIN_PATTERN, $domain) !== 1)) {
            throw new ProvisioningException('--domain must be a host name, and only works with Cloudflare.');
        }
        $current = self::sameProvider($site->storage->s3, $provider) ? $site->storage->s3 : null;
        $bucket ??= $current->bucket ?? 'sightpress-' . substr(str_replace('-', '', $site->id), 0, 24);
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,54}[a-z0-9]$/', $bucket) !== 1) {
            throw new ProvisioningException('The bucket name must be 3 to 56 lower-case letters, digits and hyphens.');
        }
        $existing = $current !== null && $current->bucket === $bucket ? self::location($current, $provider) : null;
        if ($region !== null && $existing !== null && $region !== $existing) {
            throw new ProvisioningException(sprintf('The bucket "%s" is already in %s and can\'t be moved.', $bucket, $existing));
        }
        $region ??= $existing ?? $this->defaultLocation($site, $provider);
        if ($region === null) {
            throw new ProvisioningException('This site\'s time zone is outside the UK, EU, EEA and Switzerland. Choose where its files go with --region.');
        }
        if (!self::isLocation($provider, $region)) {
            throw new ProvisioningException($provider === 'aws' ? 'The region is not valid. Use an AWS region such as eu-west-2.' : 'The jurisdiction is not valid. Use eu, us or default.');
        }

        return new ProvisioningRequest(
            $bucket,
            $region,
            $current->prefix ?? 'sites/' . $site->id,
            array_map(static fn (string $host): string => 'https://' . $host, $site->domains),
            $site->domains[0],
            $domain,
        );
    }

    public function plan(Site $site, string $provider, ProvisioningRequest $request): ProvisioningPlan
    {
        $plan = $this->provisioner($provider, false)->plan($request);
        $default = $this->defaultLocation($site, $provider);

        return $default === $request->region || $default === null ? $plan : $plan->withInternationalTransfer($default);
    }

    /**
     * @throws ProvisioningException
     */
    public function provision(string $provider, ProvisioningRequest $request): ProvisioningResult
    {
        if (!$this->hasCredentials($provider)) {
            throw new ProvisioningException(sprintf('Set %s in the server\'s environment, then run this again.', implode(' and ', self::CREDENTIALS[$provider])));
        }

        return $this->provisioner($provider, true)->provision($request);
    }

    private function provisioner(string $provider, bool $live): CloudProvisioner
    {
        if ($provider === 'cloudflare') {
            return new CloudflareProvisioner(
                $this->http,
                $this->guard ?? new EndpointGuard($this->config, new SystemHostResolver()),
                $live ? (string) $this->variable('SIGHTPRESS_CLOUDFLARE_ACCOUNT_ID') : 'plan',
                $live ? (string) $this->variable('SIGHTPRESS_CLOUDFLARE_API_TOKEN') : 'plan',
                $this->variable('SIGHTPRESS_CLOUDFLARE_ZONE_ID'),
                $this->retryDelayMs,
            );
        }
        if (array_filter($this->packages, static fn (string $class): bool => !class_exists($class)) !== []) {
            throw new ProvisioningException('Install league/flysystem-aws-s3-v3 to set up AWS storage.');
        }
        $options = [
            'region' => 'us-east-1',
            'credentials' => [
                'key' => $live ? (string) $this->variable('SIGHTPRESS_AWS_ACCESS_KEY_ID') : 'plan',
                'secret' => $live ? (string) $this->variable('SIGHTPRESS_AWS_SECRET_ACCESS_KEY') : 'plan',
            ],
            'http' => ['connect_timeout' => 10, 'timeout' => 60],
            'retries' => 2,
        ] + $this->awsOptions;

        return new LazyAwsProvisioner($options);
    }

    private function defaultLocation(Site $site, string $provider): ?string
    {
        return $provider === 'aws' ? DefaultRegion::aws($site->defaultTimezone) : DefaultRegion::cloudflare($site->defaultTimezone);
    }

    private function variable(string $name): ?string
    {
        $value = $this->environment === null ? getenv($name) : ($this->environment)($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function isLocation(string $provider, string $region): bool
    {
        return $provider === 'aws'
            ? preg_match('/^[a-z]{2}(?:-[a-z]+)+-\d{1,2}$/', $region) === 1
            : in_array($region, self::JURISDICTIONS, true);
    }

    private static function sameProvider(?SiteS3Storage $s3, string $provider): bool
    {
        if ($s3 === null) {
            return false;
        }

        return $provider === 'aws'
            ? $s3->endpoint === null
            : str_ends_with((string) parse_url((string) $s3->endpoint, PHP_URL_HOST), '.r2.cloudflarestorage.com');
    }

    private static function location(SiteS3Storage $s3, string $provider): string
    {
        if ($provider === 'aws') {
            return $s3->region;
        }

        return preg_match('/\.(eu|us)\.r2\.cloudflarestorage\.com$/', (string) parse_url((string) $s3->endpoint, PHP_URL_HOST), $match) === 1 ? $match[1] : 'default';
    }
}
