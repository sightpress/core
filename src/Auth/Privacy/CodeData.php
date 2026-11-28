<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Privacy;

use SightPress\Core\Auth\Contract\VerificationChannel;
use SightPress\Core\Auth\VerificationCodeStore;
use SightPress\Module\Privacy\ErasureResult;
use SightPress\Module\Privacy\LawfulBasis;
use SightPress\Module\Privacy\PersonalDataCategory;
use SightPress\Module\Privacy\PersonalDataProvider;
use SightPress\Module\Privacy\PersonalDataSet;
use SightPress\Module\Privacy\Retention;
use SightPress\Module\Privacy\UserRef;
use SightPress\Module\Text\Message;

/**
 * Six-digit codes (`verification_code`, D30, ADR-0075): found by account and by a keyed hash of the email address or
 * number they went to. The code and the address are never kept as they are.
 */
final readonly class CodeData implements PersonalDataProvider
{
    public const string KEY = 'auth.codes';

    public function __construct(private VerificationCodeStore $codes)
    {
    }

    public function describe(): PersonalDataSet
    {
        return new PersonalDataSet(
            self::KEY,
            new Message('Codes we sent you'),
            new Message('To check the 6-digit codes we send by email or text when you sign up, sign in or change something important.'),
            [PersonalDataCategory::Account, PersonalDataCategory::Technical],
            LawfulBasis::Contract,
            Retention::days(1),
            notes: new Message('Only a scrambled form of each code and of where it went is kept. Used and expired codes are deleted after a day.'),
        );
    }

    public function export(UserRef $user): iterable
    {
        return $this->codes->forPerson($user->userId, self::targets($user));
    }

    public function erase(UserRef $user): ErasureResult
    {
        return new ErasureResult(deleted: $this->codes->forgetPerson($user->userId, self::targets($user)));
    }

    /**
     * @return list<array{VerificationChannel, string}>
     */
    private static function targets(UserRef $user): array
    {
        $targets = [[VerificationChannel::Email, $user->email]];
        if ($user->phone !== null) {
            $targets[] = [VerificationChannel::Sms, $user->phone->e164];
        }

        return $targets;
    }
}
