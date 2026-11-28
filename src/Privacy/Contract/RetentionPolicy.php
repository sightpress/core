<?php

declare(strict_types=1);

namespace SightPress\Core\Privacy\Contract;

use DateTimeImmutable;
use Psr\EventDispatcher\EventDispatcherInterface;
use SightPress\Core\Site\Contract\SiteSettings;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Tenant\TenantRetention;
use SightPress\Module\Event\SettingsChanged;

/**
 * How long this site keeps each kind of personal data (GD-2, D45): the site's choice on *Settings → Privacy* when it
 * is within the tenant's limits ({@see TenantRetention}), the tenant's default otherwise. A stored value the tenant
 * no longer allows falls back to the default, so a narrower limit applies at once.
 */
final readonly class RetentionPolicy
{
    public const string PREFIX = 'privacy.retention.';

    public function __construct(
        private SiteSettings $settings,
        private TenantConfig $config,
        private EventDispatcherInterface $events,
    ) {
    }

    /**
     * @return list<string> every kind, in the order *Settings → Privacy* shows them
     */
    public function keys(): array
    {
        return array_keys($this->config->retention->limits);
    }

    /**
     * Days (or months, for a `…_months` kind) the site keeps it.
     *
     * @return positive-int
     */
    public function value(string $key): int
    {
        $limit = $this->config->retention->limit($key);
        $stored = $this->settings->get(self::PREFIX . $key);
        $value = $stored !== null && preg_match('/^\d{1,4}$/', $stored) === 1 ? (int) $stored : $limit['default'];

        return $value >= $limit['min'] && $value <= $limit['max'] ? $value : $limit['default'];
    }

    /**
     * @return array{default: positive-int, min: positive-int, max: positive-int}
     */
    public function limit(string $key): array
    {
        return $this->config->retention->limit($key);
    }

    /**
     * The moment before which records of this kind are no longer kept.
     */
    public function cutOff(string $key, DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->modify(sprintf('-%d %s', $this->value($key), TenantRetention::inMonths($key) ? 'months' : 'days'));
    }

    /**
     * Stores the site's choices and says so in the audit log (one `SettingsChanged` for all of them).
     *
     * @param array<string, int> $values kind => days or months
     *
     * @return list<string> the setting keys that changed
     *
     * @throws \InvalidArgumentException for an unknown kind or a value outside the tenant's limits
     */
    public function change(array $values, ?int $actorId): array
    {
        foreach ($values as $key => $value) {
            $limit = $this->limit($key);
            if ($value < $limit['min'] || $value > $limit['max']) {
                throw new \InvalidArgumentException(sprintf('Retention "%s" must be between %d and %d.', $key, $limit['min'], $limit['max']));
            }
        }
        $changed = [];
        foreach ($values as $key => $value) {
            if ($this->value($key) === $value) {
                continue;
            }
            $this->settings->set(self::PREFIX . $key, (string) $value);
            $changed[] = self::PREFIX . $key;
        }
        if ($changed !== []) {
            $this->events->dispatch(new SettingsChanged($changed, $actorId));
        }

        return $changed;
    }
}
