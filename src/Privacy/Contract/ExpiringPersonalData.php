<?php

declare(strict_types=1);

namespace SightPress\Core\Privacy\Contract;

use DateTimeImmutable;

/**
 * A core data set whose old records `privacy:purge` deletes (GD-2): its provider implements this as well as
 * {@see \SightPress\Module\Privacy\PersonalDataProvider}. Deletes in batches with DBAL, so a run that stops halfway
 * leaves nothing broken and the next run finishes the work. Modules outside core enforce their own retention.
 */
interface ExpiringPersonalData
{
    /**
     * Records deleted in one statement, at most.
     */
    public const int BATCH = 500;

    /**
     * Deletes what is older than the site keeps it.
     *
     * @return array<string, int> what was deleted, as counts by kind (`audit entries`, …), never the data
     */
    public function purgeExpired(RetentionPolicy $retention, DateTimeImmutable $now): array;
}
