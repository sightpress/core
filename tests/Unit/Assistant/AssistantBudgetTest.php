<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Assistant;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Assistant\AssistantBudget;
use SightPress\Core\Assistant\AssistantUsageStore;
use SightPress\Core\Assistant\AssistantUse;
use SightPress\Core\Assistant\BudgetStatus;
use SightPress\Core\Tests\Support\Assistant\AssistantFixture;
use SightPress\Core\Tests\Support\FixedClock;
use SightPress\Module\Assistant\TokenUsage;

#[CoversClass(AssistantBudget::class)]
#[CoversClass(BudgetStatus::class)]
#[CoversClass(AssistantUsageStore::class)]
#[CoversClass(AssistantUse::class)]
#[UsesNamespace('SightPress\Core')]
final class AssistantBudgetTest extends TestCase
{
    public function testCountsThisMonthInUtc(): void
    {
        $fixture = new AssistantFixture();
        $usage = $fixture->usage;
        $usage->record(new AssistantUse(new DateTimeImmutable('2026-10-31 23:30:00 UTC'), 3, 'summary', 'fake', 'm', new TokenUsage(500, 500), 'ok'));
        $usage->record(new AssistantUse(new DateTimeImmutable('2026-11-01 00:30:00+02:00'), 3, 'summary', 'fake', 'm', new TokenUsage(1, 1), 'ok'));
        $usage->record(new AssistantUse(new DateTimeImmutable('2026-11-05 09:00:00 UTC'), 3, 'summary', 'fake', 'm', new TokenUsage(30, 10), 'ok'));
        $usage->record(new AssistantUse(new DateTimeImmutable('2026-11-06 09:00:00 UTC'), null, 'ask', 'fake', str_repeat('m', 300), new TokenUsage(50, 0), 'timeout'));

        self::assertSame(['2026-10', '2026-10'], [AssistantUse::monthOf(new DateTimeImmutable('2026-11-01 00:30:00+02:00')), new AssistantUse(new DateTimeImmutable('2026-10-31 23:30:00 UTC'), null, 't', 'p', 'm', new TokenUsage(), 'ok')->month()]);
        self::assertSame([1002, 90, 0], [$usage->tokens('2026-10'), $usage->tokens('2026-11'), $usage->tokens('2026-12')]);
        self::assertSame([
            ['task' => 'ask', 'uses' => 1, 'failed' => 1, 'input' => 50, 'output' => 0],
            ['task' => 'summary', 'uses' => 1, 'failed' => 0, 'input' => 30, 'output' => 10],
        ], $usage->byTask('2026-11'));
        self::assertSame([], $usage->byTask('2027-01'));

        $budget = new AssistantBudget($usage, new FixedClock(new DateTimeImmutable('2026-11-20 10:00:00 UTC')));
        self::assertSame('2026-11', $budget->month());
        self::assertEquals(new BudgetStatus(90, 100), $budget->status(100));
    }

    public function testWarnsAtEightyPerCentAndRefusesAtAHundred(): void
    {
        $none = new BudgetStatus(5000, 0);
        self::assertSame([false, 0, false, false], [$none->isLimited(), $none->percent(), $none->isNearlyUsedUp(), $none->isUsedUp()]);

        $low = new BudgetStatus(79, 100);
        self::assertSame([true, 79, false, false], [$low->isLimited(), $low->percent(), $low->isNearlyUsedUp(), $low->isUsedUp()]);
        $near = new BudgetStatus(80, 100);
        self::assertSame([80, true, false], [$near->percent(), $near->isNearlyUsedUp(), $near->isUsedUp()]);
        $done = new BudgetStatus(130, 100);
        self::assertSame([130, false, true], [$done->percent(), $done->isNearlyUsedUp(), $done->isUsedUp()]);
    }
}
