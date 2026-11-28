<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Privacy;

use SightPress\Module\Privacy\ErasureResult;
use SightPress\Module\Privacy\PersonalDataProvider;
use SightPress\Module\Privacy\PersonalDataSet;
use SightPress\Module\Privacy\UserRef;

/**
 * A provider whose describe() needs its services, which the inventory check refuses.
 */
final class NeedyProvider implements PersonalDataProvider
{
    public function __construct(private ?PersonalDataSet $set)
    {
    }

    public function describe(): PersonalDataSet
    {
        return $this->set ?? throw new \LogicException('no set');
    }

    public function export(UserRef $user): iterable
    {
        return [];
    }

    public function erase(UserRef $user): ErasureResult
    {
        return ErasureResult::nothing();
    }
}
