<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Provisioning;

use SightPress\Core\Auth\PasswordReset;
use SightPress\Core\Auth\PasswordResetTokens;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Site\Contract\SiteProvisioning;
use SightPress\Core\User\Contract\Accounts;

/**
 * New site, step {@see SiteProvisioning::SIGN_IN}: makes the one-time link the first admin opens to choose their
 * password, and gives it to the operator to pass on.
 */
final readonly class FirstPasswordLink
{
    public function __invoke(SiteProvisioning $event): void
    {
        $adminId = $event->adminId();
        $account = $adminId === null ? null : Services::get($event->services, Accounts::class)->findAccount($adminId);
        if ($account === null) {
            return;
        }

        $token = Services::get($event->services, PasswordResetTokens::class)->create($account, PasswordResetTokens::SETUP_TTL);
        $event->report(sprintf(
            'Give this link to the first admin to choose their password. It works once, for %s: %s%s%s',
            PasswordReset::duration(PasswordResetTokens::SETUP_TTL),
            rtrim($event->baseUrl, '/'),
            PasswordReset::PATH,
            $token,
        ));
    }
}
