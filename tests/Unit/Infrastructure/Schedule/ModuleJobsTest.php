<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Infrastructure\Schedule;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Schedule\ModuleJobs;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Core\Tests\Support\ArrayContainer;
use SightPress\Core\Tests\Support\FakeModule;
use SightPress\Core\Tests\Support\Modules\ExampleScheduledJob;
use SightPress\Module\ModuleRegistrar;
use stdClass;

#[CoversClass(ModuleJobs::class)]
#[UsesNamespace('SightPress\Core')]
final class ModuleJobsTest extends TestCase
{
    protected function tearDown(): void
    {
        ExampleScheduledJob::$fail = false;
    }

    public function testRunsTheJobsOfTheModulesThatAreOnAndCollectsFailures(): void
    {
        $registry = ModuleRegistry::register(new ModuleLoader('0.5.0')->catalogue([
            new ModuleCandidate(new FakeModule('acme.events', register: static fn (ModuleRegistrar $r) => $r->schedule()->add(ExampleScheduledJob::class)), 'acme/events'),
        ])->resolve(new ModuleSelection()));
        $site = new ArrayContainer([ModuleRegistry::class => $registry, ExampleScheduledJob::class => new ExampleScheduledJob()]);
        $jobs = new ModuleJobs();

        self::assertSame(['Checked reminders at 09:30.'], $jobs->run($site, new DateTimeImmutable('2026-11-24 09:30 UTC')));
        self::assertSame([], $jobs->failures());

        ExampleScheduledJob::$fail = true;
        self::assertSame([], $jobs->run($site, new DateTimeImmutable('2026-11-24 09:31 UTC')));
        self::assertSame(['the job ' . ExampleScheduledJob::class . ' of module "acme.events" failed: The reminder service is down.'], $jobs->failures());

        $notAJob = new ArrayContainer([ModuleRegistry::class => $registry, ExampleScheduledJob::class => new stdClass()]);
        self::assertSame([], $jobs->run($notAJob, new DateTimeImmutable()));
        self::assertStringContainsString('is a stdClass', implode(' ', $jobs->failures()));
        self::assertSame([], $jobs->run(new ArrayContainer([ModuleRegistry::class => $registry->only([])]), new DateTimeImmutable()));
    }
}
