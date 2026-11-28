<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Modules;

use SightPress\Module\Privacy\ErasureResult;
use SightPress\Module\Privacy\LawfulBasis;
use SightPress\Module\Privacy\PersonalDataCategory;
use SightPress\Module\Privacy\PersonalDataProvider;
use SightPress\Module\Privacy\PersonalDataSet;
use SightPress\Module\Privacy\Retention;
use SightPress\Module\Privacy\UserRef;
use SightPress\Module\Text\Message;

/**
 * A module's personal data set, for registry tests.
 */
final class ExamplePersonalDataProvider implements PersonalDataProvider
{
    public function describe(): PersonalDataSet
    {
        return new PersonalDataSet('acme_events.bookings', new Message('Event bookings'), new Message('To keep your place.'), [PersonalDataCategory::Contact], LawfulBasis::Contract, Retention::days(365));
    }

    public function export(UserRef $user): iterable
    {
        yield ['user_id' => $user->userId];
    }

    public function erase(UserRef $user): ErasureResult
    {
        return new ErasureResult(deleted: 1);
    }
}
