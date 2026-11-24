<?php

declare(strict_types=1);

namespace Acme\Events;

use SightPress\Module\Migration\MigrationProvider;

final class Migrations implements MigrationProvider
{
    public function namespace(): string
    {
        return 'Acme\Events\Migrations';
    }

    public function directory(): string
    {
        return __DIR__ . '/Migrations';
    }
}
