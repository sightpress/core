<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Privacy;

use SightPress\Module\Privacy\ErasureResult;
use SightPress\Module\Privacy\LawfulBasis;
use SightPress\Module\Privacy\PersonalDataCategory;
use SightPress\Module\Privacy\PersonalDataProvider;
use SightPress\Module\Privacy\PersonalDataSet;
use SightPress\Module\Privacy\Retention;
use SightPress\Module\Privacy\UserRef;
use SightPress\Module\Text\Message;

/**
 * A provider whose data set key another one already uses, for tests.
 */
final class SameKeyProvider implements PersonalDataProvider
{
    public function describe(): PersonalDataSet
    {
        return new PersonalDataSet('acme_events.bookings', new Message('Bookings again'), new Message('Twice.'), [PersonalDataCategory::Contact], LawfulBasis::Contract, Retention::whileAccountExists());
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
