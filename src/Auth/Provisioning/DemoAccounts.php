<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Provisioning;

use SightPress\Core\Auth\Contract\VerificationCodes;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Rbac\Contract\RoleGrants;
use SightPress\Core\Site\Contract\SiteProvisioning;
use SightPress\Core\User\Contract\PendingAccounts;

/**
 * Demo content, step 2 of 4: an account for each role besides the first admin (an editor, a contributor and a
 * member), each waiting for its setup code, which is printed for the operator. Shares `demo.editor`,
 * `demo.contributor` and `demo.member`.
 */
final readonly class DemoAccounts
{
    public const int PRIORITY = SiteProvisioning::DEMO - 10;

    /** Role => [email, first name, last name]. */
    public const array ACCOUNTS = [
        'editor' => ['bea@example.org', 'Bea', 'Okafor'],
        'contributor' => ['cleo@example.org', 'Cleo', 'Martins'],
        'member' => ['max@example.org', 'Max', 'Müller'],
    ];

    public function __invoke(SiteProvisioning $event): void
    {
        if (!$event->demo) {
            return;
        }
        $accounts = Services::get($event->services, PendingAccounts::class);
        $roles = Services::get($event->services, RoleGrants::class);
        $codes = Services::get($event->services, VerificationCodes::class);
        foreach (self::ACCOUNTS as $role => [$email, $first, $last]) {
            $account = $accounts->createPending($email, $first, $last, $event->site->defaultTimezone);
            $id = (int) $account->id();
            $roles->assign($id, $role);
            $event->share('demo.' . $role, $id);
            $event->report(sprintf('Demo %s %s: ', $role, $first) . FirstSetupCode::instructions($codes, $id, $email, $event->baseUrl));
        }
    }
}
