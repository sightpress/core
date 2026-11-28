<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Privacy;

use SightPress\Module\Privacy\ErasureResult;
use SightPress\Module\Privacy\ErasureRule;
use SightPress\Module\Privacy\LawfulBasis;
use SightPress\Module\Privacy\PersonalDataCategory;
use SightPress\Module\Privacy\PersonalDataProvider;
use SightPress\Module\Privacy\PersonalDataSet;
use SightPress\Module\Privacy\Retention;
use SightPress\Module\Privacy\UserRef;
use SightPress\Module\Text\Message;

/**
 * Limits on sign-ins, codes and forms (`Http\RateLimit\RateLimiters`, ADR-0075). The cache keeps only a keyed hash
 * of each key (an email address with the client address, an account id), so an entry can't be found or read back
 * for one person; every entry ends by itself within a day (a window and the ones after it that it still
 * counts towards).
 */
final readonly class ThrottleData implements PersonalDataProvider
{
    public const string KEY = 'auth.throttle';

    public function describe(): PersonalDataSet
    {
        return new PersonalDataSet(
            self::KEY,
            new Message('Limits on attempts'),
            new Message('To stop password guessing and the misuse of forms, by counting attempts for a short time.'),
            [PersonalDataCategory::Technical],
            LawfulBasis::LegitimateInterests,
            Retention::days(1),
            ErasureRule::Keep,
            new Message('Only counts are kept, under a scrambled form of the email address or account and the internet address that tried, so they can’t be linked back to you or listed. They end by themselves within a day.'),
        );
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
