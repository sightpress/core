<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support;

use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * PHPUnit extension: when the run is over, drops the test databases that were still in use when their test ended
 * ({@see TestDatabases::dropLeftovers()}). Does nothing on SQLite.
 */
final class TestDatabaseCleanup implements Extension, ExecutionFinishedSubscriber
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber($this);
    }

    public function notify(ExecutionFinished $event): void
    {
        TestDatabases::dropLeftovers();
    }
}
