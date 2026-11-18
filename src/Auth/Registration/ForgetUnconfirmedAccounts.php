<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Registration;

use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Site\Contract\ScheduleDue;
use SightPress\Core\User\Contract\UnverifiedAccounts;

/**
 * The people module's job in `schedule:run`: removes the accounts people made for themselves and never confirmed,
 * a week ({@see AFTER}) after they were made.
 */
final readonly class ForgetUnconfirmedAccounts
{
    public const string AFTER = '7 days';

    public function __invoke(ScheduleDue $event): void
    {
        $removed = Services::get($event->services, UnverifiedAccounts::class)->forgetUnconfirmed($event->now->modify('-' . self::AFTER));
        if ($removed > 0) {
            $event->report(sprintf('Removed %d %s whose email address was never confirmed.', $removed, $removed === 1 ? 'account' : 'accounts'));
        }
    }
}
