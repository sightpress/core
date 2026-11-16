<?php

declare(strict_types=1);

namespace SightPress\Core\User\Contract;

/**
 * Every account of the site, for the people who manage them: listing, deactivating and reactivating. A deactivated
 * person can't sign in (open sessions end on their next request); their content stays. Each change dispatches
 * {@see AccountChanged} with the field `status`.
 */
interface AccountRoster
{
    /**
     * @return list<AccountSummary> ordered by id
     */
    public function summaries(): array;

    public function summary(int $id): ?AccountSummary;

    /**
     * @throws AccountException when there is no such account
     */
    public function deactivate(int $id, ?int $changedBy): void;

    /**
     * @throws AccountException when there is no such account
     */
    public function reactivate(int $id, ?int $changedBy): void;
}
