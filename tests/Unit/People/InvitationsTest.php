<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\People;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Auth\Contract\TooManyCodes;
use SightPress\Core\Auth\AnyEmailPolicy;
use SightPress\Core\People\Invitation;
use SightPress\Core\People\Invitations;
use SightPress\Core\People\Migrations\Version20261116180000;
use SightPress\Core\People\Migrations\Version20261117100000;
use SightPress\Core\Tests\Support\AuthFixture;

#[CoversClass(Invitations::class)]
#[CoversClass(Invitation::class)]
#[CoversClass(AnyEmailPolicy::class)]
#[CoversClass(Version20261117100000::class)]
#[UsesNamespace('SightPress\Core')]
final class InvitationsTest extends TestCase
{
    private const string CLIENT = '192.0.2.10';

    private AuthFixture $auth;

    private Invitations $invitations;

    protected function setUp(): void
    {
        $this->auth = new AuthFixture();
        $this->invitations = new Invitations($this->auth->database->connection, $this->auth->clock, $this->auth->codes());
    }

    public function testAnInvitationHasACodeThatWorksOnceForSevenDays(): void
    {
        [$invitation, $code] = $this->invitations->create(' Nia@Example.org ', 'contributor', 'Nia', null, 3);

        self::assertMatchesRegularExpression('/^\d{6}$/', $code->code);
        self::assertSame('nia@example.org', $invitation->email);
        self::assertSame(['contributor', 'Nia', null, 3], [$invitation->role, $invitation->firstName, $invitation->lastName, $invitation->invitedBy]);
        self::assertEquals($this->auth->clock->now()->modify('+7 days'), $invitation->expiresAt);
        self::assertEquals($invitation->expiresAt, $code->expiresAt);
        $columns = $this->columns();
        self::assertNotContains('token_hash', $columns, 'no link token any more');

        self::assertNull($this->invitations->verify('max@example.org', $code->code, self::CLIENT), 'another address');
        $found = $this->invitations->verify('NIA@example.org', $code->readable(), self::CLIENT);
        self::assertNotNull($found);
        self::assertSame($invitation->id, $found->id);
        self::assertEquals($invitation->createdAt, $found->createdAt);
        self::assertNull($found->acceptedAt);
        self::assertNull($this->invitations->verify('nia@example.org', $code->code, self::CLIENT), 'the code works once');
        self::assertSame($invitation->id, $this->invitations->findOpen($invitation->id)?->id);
        self::assertSame([$invitation->id], array_map(static fn (Invitation $i): int => $i->id, $this->invitations->open()));

        $this->invitations->accept($found);
        self::assertNull($this->invitations->findOpen($invitation->id));
        self::assertSame([], $this->invitations->open());
    }

    public function testAnExpiredOrAcceptedInvitationCantBeVerified(): void
    {
        [$accepted, $acceptedCode] = $this->invitations->create('max@example.org', 'member', null, null, null);
        $this->invitations->accept($accepted);
        self::assertNull($this->invitations->verify('max@example.org', $acceptedCode->code, self::CLIENT), 'the code is right, but the invitation was used');

        [$invitation, $code] = $this->invitations->create('nia@example.org', 'contributor', null, null, null);
        $this->auth->clock->advance(Invitations::TTL);
        self::assertNull($this->invitations->verify('nia@example.org', $code->code, self::CLIENT));
        self::assertNull($this->invitations->findOpen($invitation->id));
        self::assertSame([], $this->invitations->open());
    }

    public function testInvitingTheSameAddressAgainReplacesTheOpenInvitationAndItsCode(): void
    {
        [, $old] = $this->invitations->create('nia@example.org', 'contributor', null, null, null);
        [$accepted] = $this->invitations->create('max@example.org', 'member', null, null, null);
        $this->invitations->accept($accepted);
        $this->auth->clock->advance(60);

        [$new, $code] = $this->invitations->create('NIA@example.org', 'editor', null, 'Lind', 1);
        $this->invitations->create('max@example.org', 'member', null, null, null);

        if ($old->code !== $code->code) {
            self::assertNull($this->invitations->verify('nia@example.org', $old->code, self::CLIENT));
        }
        self::assertSame([$new->id + 1, $new->id], array_map(static fn (Invitation $i): int => $i->id, $this->invitations->open()));
        self::assertSame('editor', $this->invitations->verify('nia@example.org', $code->code, self::CLIENT)?->role);
        self::assertCount(2, $this->auth->database->connection->createQueryBuilder()->select('id')->from('invitations')->where('email = ?')->setParameter(0, 'max@example.org')->executeQuery()->fetchAllAssociative(), 'the accepted one is kept');
        self::assertNull(new AnyEmailPolicy()->problem('anyone@anywhere.example'));
    }

    public function testTooManyTriesFromOneClientAreRefused(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            $this->invitations->verify('nia@example.org', '123456', self::CLIENT);
        }

        $this->expectException(TooManyCodes::class);
        $this->invitations->verify('nia@example.org', '123456', self::CLIENT);
    }

    public function testTheMigrationDropsTheLinkTokenAndCanBeUndone(): void
    {
        $database = $this->auth->database;
        $database->migrate(Version20261116180000::class);
        self::assertContains('token_hash', $this->columns());
        $database->migrate();
        self::assertNotContains('token_hash', $this->columns());
        self::assertSame('Drop invitations.token_hash: invitations use codes, not links.', new Version20261117100000($database->connection, new \Psr\Log\NullLogger())->getDescription());
    }

    /**
     * @return list<string>
     */
    private function columns(): array
    {
        return array_values(array_map(
            static fn ($column): string => $column->getName(),
            $this->auth->database->connection->createSchemaManager()->listTableColumns('invitations'),
        ));
    }
}
