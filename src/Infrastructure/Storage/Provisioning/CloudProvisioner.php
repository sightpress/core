<?php

declare(strict_types=1);

namespace SightPress\Core\Infrastructure\Storage\Provisioning;

/**
 * Sets up a site's storage at a cloud provider. Running it again changes nothing that is already right, and keeps
 * rules that other tools or sites added to a shared bucket.
 */
interface CloudProvisioner
{
    public function plan(ProvisioningRequest $request): ProvisioningPlan;

    /**
     * @throws ProvisioningException
     */
    public function provision(ProvisioningRequest $request): ProvisioningResult;
}
