<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Registration;

use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Privacy\Contract\RetentionPolicy;
use SightPress\Core\Tenant\TenantRetention;
use SightPress\Core\Site\Contract\ScheduleDue;
use SightPress\Core\User\Contract\UnverifiedAccounts;

/**
 * The people module's job in `schedule:run`: removes the accounts people made for themselves and never confirmed,
 * as long after they were made as the site keeps them (`privacy.retention.unverified_days`, a week by default).
 */
final readonly class ForgetUnconfirmedAccounts
{
    public function __invoke(ScheduleDue $event): void
    {
        $before = Services::get($event->services, RetentionPolicy::class)->cutOff(TenantRetention::UNVERIFIED, $event->now);
        $removed = Services::get($event->services, UnverifiedAccounts::class)->forgetUnconfirmed($before);
        if ($removed > 0) {
            $event->report(sprintf('Removed %d %s whose email address was never confirmed.', $removed, $removed === 1 ? 'account' : 'accounts'));
        }
    }
}
