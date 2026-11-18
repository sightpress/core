<?php

declare(strict_types=1);

namespace SightPress\Core\Site\Contract;

use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use SightPress\Core\Tenant\Site;

/**
 * Dispatched by `schedule:run` for each enabled site, once a minute: the jobs that run at a set time listen to it
 * (`subscribers()->listen(ScheduleDue::class, …)`), for example scheduled publishing, roll-ups and clean-ups.
 *
 * A listener takes the services it needs from {@see self::$services} (the site's container, against its own
 * database), does what is due at {@see self::$now} (UTC) and tells the operator what it did with {@see report()}.
 * Jobs must be safe to run again: a run that was missed is caught up by the next one, never by running the missed
 * minutes one by one. If a listener throws, the run reports the site as failed and goes on with the next site.
 */
final class ScheduleDue
{
    /** @var list<string> */
    private array $lines = [];

    public function __construct(
        public readonly Site $site,
        public readonly ContainerInterface $services,
        public readonly DateTimeImmutable $now,
    ) {
    }

    /**
     * A sentence for the operator about what a job did.
     */
    public function report(string $line): void
    {
        $this->lines[] = $line;
    }

    /**
     * @return list<string>
     */
    public function lines(): array
    {
        return $this->lines;
    }
}
