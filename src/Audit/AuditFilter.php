<?php

declare(strict_types=1);

namespace SightPress\Core\Audit;

use DateTimeImmutable;

/**
 * Which entries to show: by who, by kind of thing, from and up to a day (whole days in the viewer's time zone,
 * turned into UTC instants by the screen).
 */
final readonly class AuditFilter
{
    public function __construct(
        public ?int $actorId = null,
        public ?string $entityType = null,
        public ?DateTimeImmutable $from = null,
        public ?DateTimeImmutable $before = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->actorId === null && $this->entityType === null && $this->from === null && $this->before === null;
    }
}
