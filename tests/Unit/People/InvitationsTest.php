<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\People;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\People\AnyEmailPolicy;
use SightPress\Core\People\Invitation;
use SightPress\Core\People\Invitations;
use SightPress\Core\Tests\Support\CoreSiteDatabase;
use SightPress\Core\Tests\Support\FixedClock;

#[CoversClass(Invitations::class)]
#[CoversClass(Invitation::class)]
#[CoversClass(AnyEmailPolicy::class)]
#[UsesNamespace('SightPress\Core')]
final class InvitationsTest extends TestCase
{
    private CoreSiteDatabase $database;

    private FixedClock $clock;

    private Invitations $invitations;

    protected function setUp(): void
    {
        $this->database = new CoreSiteDatabase();
        $this->clock = new FixedClock();
        $this->invitations = new Invitations($this->database->connection, $this->clock);
    }

    public function testAnInvitationWorksOnceForSevenDaysAndOnlyItsHashIsStored(): void
    {
        [$invitation, $token] = $this->invitations->create(' Nia@Example.org ', 'contributor', 'Nia', null, 3);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token);
        self::assertSame('nia@example.org', $invitation->email);
        self::assertSame(['contributor', 'Nia', null, 3], [$invitation->role, $invitation->firstName, $invitation->lastName, $invitation->invitedBy]);
        self::assertEquals($this->clock->now()->modify('+7 days'), $invitation->expiresAt);
        $stored = $this->database->connection->createQueryBuilder()->select('token_hash')->from('invitations')->executeQuery()->fetchOne();
        self::assertSame(hash('sha256', $token), $stored);

        $found = $this->invitations->find($token);
        self::assertNotNull($found);
        self::assertSame($invitation->id, $found->id);
        self::assertEquals($invitation->createdAt, $found->createdAt);
        self::assertNull($found->acceptedAt);
        self::assertSame([$invitation->id], array_map(static fn (Invitation $i): int => $i->id, $this->invitations->open()));

        $this->invitations->accept($found);
        self::assertNull($this->invitations->find($token));
        self::assertSame([], $this->invitations->open());
    }

    public function testExpiredMalformedAndUnknownLinksFindNothing(): void
    {
        [, $token] = $this->invitations->create('nia@example.org', 'contributor', null, null, null);

        self::assertNull($this->invitations->find('not-a-token'));
        self::assertNull($this->invitations->find(str_repeat('a', 43)));
        $this->clock->advance(Invitations::TTL);
        self::assertNull($this->invitations->find($token));
        self::assertSame([], $this->invitations->open());
    }

    public function testInvitingTheSameAddressAgainReplacesTheOpenInvitation(): void
    {
        [, $old] = $this->invitations->create('nia@example.org', 'contributor', null, null, null);
        [$accepted, $acceptedToken] = $this->invitations->create('max@example.org', 'member', null, null, null);
        $this->invitations->accept($accepted);
        $this->clock->advance(60);

        [$new, $token] = $this->invitations->create('NIA@example.org', 'editor', null, 'Lind', 1);
        $this->invitations->create('max@example.org', 'member', null, null, null);

        self::assertNull($this->invitations->find($old));
        self::assertSame('editor', $this->invitations->find($token)?->role);
        self::assertSame([$new->id + 1, $new->id], array_map(static fn (Invitation $i): int => $i->id, $this->invitations->open()));
        self::assertNull($this->invitations->find($acceptedToken));
        self::assertCount(2, $this->database->connection->createQueryBuilder()->select('id')->from('invitations')->where('email = ?')->setParameter(0, 'max@example.org')->executeQuery()->fetchAllAssociative(), 'the accepted one is kept');
        self::assertNull(new AnyEmailPolicy()->problem('anyone@anywhere.example'));
    }
}
