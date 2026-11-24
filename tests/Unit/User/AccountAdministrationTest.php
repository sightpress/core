<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\User;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Tests\Support\AuthFixture;
use SightPress\Core\Tests\Support\RecordingEvents;
use SightPress\Core\User\AccountAdministration;
use SightPress\Module\Event\AccountChanged;
use SightPress\Core\User\Contract\AccountException;
use SightPress\Core\User\Contract\AccountSummary;

#[CoversClass(AccountAdministration::class)]
#[CoversClass(AccountSummary::class)]
#[UsesNamespace('SightPress\Core')]
final class AccountAdministrationTest extends TestCase
{
    private AuthFixture $auth;

    private RecordingEvents $events;

    private AccountAdministration $roster;

    protected function setUp(): void
    {
        $this->auth = new AuthFixture();
        $this->events = new RecordingEvents();
        $this->roster = new AccountAdministration($this->auth->users, $this->auth->clock, $this->events);
    }

    public function testSummarisesEveryAccountForTheList(): void
    {
        $ada = $this->auth->user('ada@example.org');
        $this->auth->avatars()->generate($ada);
        $this->auth->users->save($ada);
        $pending = $this->auth->user('nia@example.org', pending: true);

        $summaries = $this->roster->summaries();

        self::assertCount(2, $summaries);
        self::assertSame([(int) $ada->id(), 'ada@example.org', 'Ada', 'Ada Lovelace', 'active'], [$summaries[0]->id, $summaries[0]->email, $summaries[0]->effectiveName, $summaries[0]->fullName, $summaries[0]->status]);
        self::assertMatchesRegularExpression('#^/avatars/[0-9a-f]{32}\.svg$#', (string) $summaries[0]->avatarUrl);
        self::assertSame(AccountSummary::PENDING, $summaries[1]->status);
        self::assertNull($summaries[1]->avatarUrl);
        self::assertEquals($summaries[1], $this->roster->summary((int) $pending->id()));
        self::assertNull($this->roster->summary(999));
        self::assertFalse($summaries[0]->isUnverified());

        $nia = $this->auth->user('nia.lind@example.org', unverified: true);
        $unverified = $this->roster->summary((int) $nia->id());
        self::assertSame(AccountSummary::UNVERIFIED, $unverified?->status);
        self::assertTrue($unverified->isUnverified());
        self::assertFalse($unverified->isDeactivated());
    }

    public function testDeactivatesAndReactivatesAndSaysWhoDidIt(): void
    {
        $ada = $this->auth->user('ada@example.org');
        $id = (int) $ada->id();

        $this->roster->deactivate($id, 7);
        self::assertTrue($this->roster->summary($id)?->isDeactivated());
        self::assertFalse($this->auth->users->find($id)?->isActive());

        $this->roster->reactivate($id, 7);
        $summary = $this->roster->summary($id);
        self::assertNotNull($summary);
        self::assertSame(AccountSummary::ACTIVE, $summary->status);
        self::assertFalse($summary->isDeactivated());
        self::assertEquals([new AccountChanged($id, 7, ['status']), new AccountChanged($id, 7, ['status'])], $this->events->events);
    }

    public function testAnUnknownAccountIsAnError(): void
    {
        $this->expectException(AccountException::class);
        $this->expectExceptionMessage('There is no account 999.');

        $this->roster->deactivate(999, 1);
    }
}
