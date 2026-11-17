<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Provisioning;

use SightPress\Core\Auth\Contract\CodeEntry;
use SightPress\Core\Auth\Contract\VerificationChannel;
use SightPress\Core\Auth\Contract\VerificationCodes;
use SightPress\Core\Auth\Contract\VerificationPurpose;
use SightPress\Core\Auth\Http\SetupAccountHandler;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Site\Contract\SiteProvisioning;
use SightPress\Core\User\Contract\Accounts;

/**
 * New site, step {@see SiteProvisioning::SIGN_IN}: makes the one-time setup code the first admin types, with their
 * email address, on the site's "Set up your account" page, and gives it to the operator to pass on. Never a link.
 */
final readonly class FirstSetupCode
{
    public function __invoke(SiteProvisioning $event): void
    {
        $adminId = $event->adminId();
        $account = $adminId === null ? null : Services::get($event->services, Accounts::class)->findAccount($adminId);
        if ($account === null) {
            return;
        }

        $event->report(self::instructions(
            Services::get($event->services, VerificationCodes::class),
            $adminId,
            $account->email(),
            $event->baseUrl,
        ));
    }

    /**
     * Makes a setup code for the account and says, for the operator, what to give the person.
     */
    public static function instructions(VerificationCodes $codes, int $accountId, string $email, string $baseUrl): string
    {
        $code = $codes->issue(VerificationPurpose::AccountSetup, VerificationChannel::Email, $email, $accountId);

        return sprintf(
            'Give the setup code %s to %s. They go to %s%s, type their email address and the code, and choose '
            . 'their password. The code works once, for %s.',
            $code->readable(),
            $email,
            rtrim($baseUrl, '/'),
            SetupAccountHandler::PATH,
            CodeEntry::duration(VerificationPurpose::AccountSetup->lifetime(VerificationChannel::Email)),
        );
    }
}
