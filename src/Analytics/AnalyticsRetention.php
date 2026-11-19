<?php

declare(strict_types=1);

namespace SightPress\Core\Analytics;

use DateTimeImmutable;
use InvalidArgumentException;
use Psr\EventDispatcher\EventDispatcherInterface;
use SightPress\Core\Site\Contract\SettingsChanged;
use SightPress\Core\Site\Contract\SiteSettings;

/**
 * How many months of daily figures a site keeps (D21): {@see DEFAULT} unless someone with `analytics.manage` sets
 * from {@see MIN} to {@see MAX}. Each change is a {@see SettingsChanged}, so it is in the audit log.
 */
final readonly class AnalyticsRetention
{
    public const string SETTING = 'analytics.retention_months';
    public const int DEFAULT = 25;
    public const int MIN = 1;
    public const int MAX = 25;

    public function __construct(
        private SiteSettings $settings,
        private EventDispatcherInterface $events,
    ) {
    }

    public function months(): int
    {
        $stored = $this->settings->get(self::SETTING);
        $months = $stored !== null && preg_match('/^\d{1,2}$/', $stored) === 1 ? (int) $stored : self::DEFAULT;

        return $months >= self::MIN && $months <= self::MAX ? $months : self::DEFAULT;
    }

    /**
     * @throws InvalidArgumentException outside {@see MIN} … {@see MAX}
     */
    public function change(int $months, ?int $actorId): void
    {
        if ($months < self::MIN || $months > self::MAX) {
            throw new InvalidArgumentException(sprintf('Keep analytics for %d to %d months.', self::MIN, self::MAX));
        }
        if ($this->settings->get(self::SETTING) === (string) $months) {
            return;
        }
        $this->settings->set(self::SETTING, (string) $months);
        $this->events->dispatch(new SettingsChanged([self::SETTING], $actorId));
    }

    /**
     * The first day whose figures are kept, counted back from today (`Y-m-d`, in the site's time zone).
     */
    public function firstKeptDay(string $today): string
    {
        return new DateTimeImmutable($today)->modify(sprintf('-%d months', $this->months()))->format('Y-m-d');
    }
}
