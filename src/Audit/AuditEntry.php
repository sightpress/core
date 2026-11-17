<?php

declare(strict_types=1);

namespace SightPress\Core\Audit;

use DateTimeImmutable;

/**
 * One line of the audit log: when, who (an account id, or null for the command line and the system), what was done
 * (`page.published`), to what (`page` 12) and a plain sentence that says it, with the details as data.
 */
final readonly class AuditEntry
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public DateTimeImmutable $occurredAt,
        public ?int $actorId,
        public string $action,
        public string $entityType,
        public ?string $entityId,
        public string $summary,
        public array $details = [],
        public ?int $id = null,
    ) {
    }
}
