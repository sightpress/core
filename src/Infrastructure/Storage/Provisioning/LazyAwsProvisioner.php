<?php

declare(strict_types=1);

namespace SightPress\Core\Infrastructure\Storage\Provisioning;

use Aws\CloudFront\CloudFrontClient;
use Aws\S3\S3Client;

/**
 * Builds the AWS clients for the request's region, which is only known once the request is made.
 */
final readonly class LazyAwsProvisioner implements CloudProvisioner
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(private array $options)
    {
    }

    public function plan(ProvisioningRequest $request): ProvisioningPlan
    {
        return $this->provisioner($request)->plan($request);
    }

    public function provision(ProvisioningRequest $request): ProvisioningResult
    {
        return $this->provisioner($request)->provision($request);
    }

    private function provisioner(ProvisioningRequest $request): AwsProvisioner
    {
        return new AwsProvisioner(
            new S3Client(['version' => '2006-03-01', 'region' => $request->region] + $this->options),
            new CloudFrontClient(['version' => '2020-05-31'] + $this->options),
        );
    }
}
