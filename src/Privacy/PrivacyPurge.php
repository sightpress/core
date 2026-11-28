<?php

declare(strict_types=1);

namespace SightPress\Core\Privacy;

use DateTimeImmutable;
use SightPress\Core\Privacy\Contract\ExpiringPersonalData;
use SightPress\Core\Privacy\Contract\PersonalDataInventory;
use SightPress\Core\Privacy\Contract\RetentionPolicy;
use SightPress\Core\Site\Contract\SiteSettings;
use SightPress\Module\Audit\AuditRecord;
use SightPress\Module\Audit\AuditTrail;

/**
 * `privacy:purge` (GD-2, D45): deletes what the site keeps longer than its retention, for every data set of the
 * modules that are on whose provider can ({@see ExpiringPersonalData}). Each run is in the audit log with counts only.
 * Runs once a day from `schedule:run`, or at once with `site:privacy:purge`. Every delete is batched and idempotent, so
 * a run that is stopped halfway is finished by the next one.
 */
final readonly class PrivacyPurge
{
    /** The site setting with the day (`Y-m-d`, UTC) of the last run. */
    public const string LAST_RUN = 'privacy.purged_on';

    public function __construct(
        private PersonalDataInventory $inventory,
        private RetentionPolicy $retention,
        private AuditTrail $audit,
        private SiteSettings $settings,
    ) {
    }

    public function isDue(DateTimeImmutable $now): bool
    {
        return $this->settings->get(self::LAST_RUN) !== self::day($now);
    }

    /**
     * @param int|null $actorId who asked for it; null for `schedule:run` and the command line
     *
     * @return array<string, int> what was deleted, by kind
     */
    public function run(DateTimeImmutable $now, ?int $actorId = null): array
    {
        $counts = [];
        foreach ($this->inventory->providers() as $provider) {
            if (!$provider instanceof ExpiringPersonalData) {
                continue;
            }
            foreach ($provider->purgeExpired($this->retention, $now) as $kind => $count) {
                $counts[$kind] = ($counts[$kind] ?? 0) + $count;
            }
        }
        $this->settings->set(self::LAST_RUN, self::day($now));
        $removed = array_filter($counts, static fn (int $count): bool => $count > 0);
        $this->audit->record(new AuditRecord(
            'privacy.purged',
            $removed === []
                ? 'Checked the personal data the site keeps: nothing was older than the site keeps it.'
                : sprintf('Deleted personal data older than the site keeps it: %s.', implode(', ', array_map(static fn (string $kind, int $count): string => $count . ' ' . $kind, array_keys($removed), $removed))),
            $actorId,
            'privacy',
            null,
            $counts,
        ));

        return $counts;
    }

    private static function day(DateTimeImmutable $now): string
    {
        return $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
    }
}
