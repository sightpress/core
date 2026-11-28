<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Http\Session;

use PHPUnit\Framework\TestCase;
use SightPress\Core\Http\Session\ExpiringSessionStore;
use SightPress\Core\Http\Session\PersonSessions;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Http\Session\SessionStore;

/**
 * What every {@see SessionStore} must do (H-9). Core runs it against the database, Redis and file stores; a store of
 * your own (`session.store` = your class) can extend it too: say how to build the store, how to let time pass and
 * what the store keeps.
 */
abstract class SessionStoreContract extends TestCase
{
    private const string ID = 'aB3dE5fG7hJ9kL1mN3pQ5rS7tU9vW1xY3zA5bC7dE9f';

    /**
     * @param positive-int $idleTimeout
     */
    abstract protected function store(int $idleTimeout = 3600): SessionStore;

    /**
     * Lets this many seconds pass for the store.
     */
    abstract protected function wait(int $seconds): void;

    /**
     * Everything the store keeps (keys, file names, rows and values), to look for raw session ids in.
     */
    abstract protected function stored(): string;

    public function testAnUnknownSessionHasNoData(): void
    {
        self::assertNull($this->store()->read(self::ID));
    }

    public function testKeepsWhatIsWrittenUntilItIsDeleted(): void
    {
        $store = $this->store();
        $store->write(self::ID, ['user_id' => 7, 'csrf' => ['form' => 'token'], 'flash' => [['text' => 'Saved']]]);

        self::assertSame(['user_id' => 7, 'csrf' => ['form' => 'token'], 'flash' => [['text' => 'Saved']]], $store->read(self::ID));
        self::assertSame(['user_id' => 7, 'csrf' => ['form' => 'token'], 'flash' => [['text' => 'Saved']]], $this->store()->read(self::ID), 'another server reads the same session');

        $store->write(self::ID, ['user_id' => 8]);
        self::assertSame(['user_id' => 8], $store->read(self::ID), 'a write replaces the data');
        $store->write(self::ID, ['user_id' => 8]);
        self::assertSame(['user_id' => 8], $store->read(self::ID), 'writing the same data again is fine');

        $store->delete(self::ID);
        self::assertNull($store->read(self::ID));
        $store->delete(self::ID);
        self::assertNull($store->read(self::ID), 'deleting a session that is gone is fine');
    }

    public function testKeepsSessionsApart(): void
    {
        $store = $this->store();
        $store->write(self::ID, ['user_id' => 1]);
        $store->write(strrev(self::ID), ['user_id' => 2]);
        $store->delete(strrev(self::ID));

        self::assertSame(['user_id' => 1], $store->read(self::ID));
    }

    public function testTouchingKeepsTheDataAndNeverBringsASessionBack(): void
    {
        $store = $this->store();
        $store->write(self::ID, ['user_id' => 7]);
        $store->touch(self::ID);
        self::assertSame(['user_id' => 7], $store->read(self::ID));

        $store->delete(self::ID);
        $store->touch(self::ID);
        self::assertNull($store->read(self::ID));
    }

    public function testASessionEndsAfterTheIdleTimeoutAndStaysEnded(): void
    {
        $store = $this->store(2);
        $store->write(self::ID, ['user_id' => 7]);
        $this->wait(3);

        self::assertNull($store->read(self::ID), 'an expired session is refused');
        $store->touch(self::ID);
        self::assertNull($store->read(self::ID), 'touching an expired session does not bring it back');
        if ($store instanceof ExpiringSessionStore) {
            self::assertLessThanOrEqual(1, $store->forgetExpired(new \DateTimeImmutable('+1 year')), 'reading it may have removed it already');
            self::assertSame(0, $store->forgetExpired(new \DateTimeImmutable('+1 year')));
        }
        self::assertStringNotContainsString(hash('sha256', self::ID), $this->stored(), 'an expired session is removed');
    }

    public function testFindsAndEndsEverySessionOfOnePerson(): void
    {
        $store = $this->store();
        if (!$store instanceof PersonSessions) {
            self::markTestSkipped('The store can\'t find a person\'s sessions.');
        }
        $store->write('laptop', [Session::USER_ID => 7]);
        $store->write('phone', [Session::USER_ID => 7, 'flash' => []]);
        $store->write('other', [Session::USER_ID => 8]);
        $store->write('visitor', ['csrf' => ['form' => 'token']]);
        $store->write('signed-out', [Session::USER_ID => 7]);
        $store->delete('signed-out');

        $sessions = $store->forPerson(7);
        self::assertCount(2, $sessions);
        foreach ($sessions as $session) {
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', (string) $session['last_seen_at']);
            self::assertGreaterThan($session['last_seen_at'], $session['expires_at']);
        }
        self::assertSame([], $store->forPerson(9));

        self::assertSame(2, $store->forgetPerson(7));
        self::assertNull($store->read('laptop'));
        self::assertNull($store->read('phone'));
        self::assertSame([], $store->forPerson(7));
        self::assertSame([Session::USER_ID => 8], $store->read('other'), 'other people stay signed in');
        self::assertNotNull($store->read('visitor'));
        self::assertSame(0, $store->forgetPerson(7));
    }

    public function testNeverKeepsARawSessionId(): void
    {
        $store = $this->store();
        $store->write(self::ID, ['user_id' => 7]);

        self::assertStringNotContainsString(self::ID, $this->stored());
        self::assertStringContainsString(hash('sha256', self::ID), $this->stored(), 'only its hash');
    }
}
