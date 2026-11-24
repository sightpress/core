<?php

declare(strict_types=1);

namespace SightPress\Core\Audit;

use Psr\Clock\ClockInterface;
use SightPress\Module\Audit\AuditRecord;
use SightPress\Module\Audit\AuditTrail;

/**
 * Core's side of module-interface `AuditTrail` (ADR-0059): a module's record becomes a line of the site's audit log.
 */
final readonly class ModuleAuditTrail implements AuditTrail
{
    public function __construct(
        private AuditLog $log,
        private ClockInterface $clock,
    ) {
    }

    public function record(AuditRecord $record): void
    {
        $this->log->append(new AuditEntry(
            $this->clock->now(),
            $record->actorId,
            $record->action,
            $record->subjectType ?? 'module',
            $record->subjectId === null ? null : (string) $record->subjectId,
            $record->summary,
            $record->details,
        ));
    }
}
