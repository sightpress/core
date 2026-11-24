<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Module;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Module\UnavailableModule;
use SightPress\Core\Module\UnavailableModuleLog;
use SightPress\Core\Tests\Support\RecordingLogger;
use SightPress\Core\Tests\Support\TemporaryDirectory;

#[CoversClass(UnavailableModuleLog::class)]
#[CoversClass(UnavailableModule::class)]
final class UnavailableModuleLogTest extends TestCase
{
    public function testLogsTheListOnceUntilItChanges(): void
    {
        $folder = new TemporaryDirectory();
        $logger = new RecordingLogger();
        $broken = [new UnavailableModule('acme/events', 'acme.events', 'It needs sightpress/module-interface ^9.0.')];
        $log = UnavailableModuleLog::inCacheFolder($logger, $folder->path . '/cache/');

        $log->report($broken);
        $log->report($broken);
        self::assertSame([['error', 'Module acme.events (acme/events) is unavailable and was left out of every site: It needs sightpress/module-interface ^9.0.']], $logger->records);

        $log->report([...$broken, new UnavailableModule('acme/tools', null, 'It does not load.')]);
        self::assertSame('Module acme/tools is unavailable and was left out of every site: It does not load.', $logger->messages()[2]);
        self::assertCount(3, $logger->records);

        // Without a cache folder (debug mode), every boot logs it.
        $debug = UnavailableModuleLog::inCacheFolder($logger, null);
        $debug->report($broken);
        $debug->report($broken);
        self::assertCount(5, $logger->records);
        $folder->remove();
    }
}
