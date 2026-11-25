<?php

declare(strict_types=1);

namespace SightPress\Core\Sms;

use SightPress\Module\Migration\MigrationProvider;

/**
 * The text message module's tables: the message log and outbox, STOP replies and webhook reports (ADR-0069).
 */
final readonly class SmsMigrations implements MigrationProvider
{
    public function namespace(): string
    {
        return __NAMESPACE__ . '\Migrations';
    }

    public function directory(): string
    {
        return __DIR__ . '/Migrations';
    }
}
