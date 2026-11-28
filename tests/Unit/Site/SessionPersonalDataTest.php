<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Site;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Http\Session\FileSessionStore;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Http\Session\SessionMiddleware;
use SightPress\Core\Http\Session\SessionStore;
use SightPress\Core\Site\SessionPersonalData;
use SightPress\Core\Tests\Support\FixedClock;
use SightPress\Core\Tests\Support\TemporaryDirectory;
use SightPress\Module\Privacy\LawfulBasis;
use SightPress\Module\Privacy\PersonalDataCategory;
use SightPress\Module\Privacy\UserRef;

#[CoversClass(SessionPersonalData::class)]
#[UsesClass(FileSessionStore::class)]
#[UsesClass(Session::class)]
final class SessionPersonalDataTest extends TestCase
{
    public function testDescribesTheSessionsAndTheirCookie(): void
    {
        $set = new SessionPersonalData(self::createStub(SessionStore::class))->describe();

        self::assertSame('site.sessions', $set->key);
        self::assertSame('Sign-in sessions', $set->label->text);
        self::assertSame([PersonalDataCategory::Technical, PersonalDataCategory::Account], $set->categories);
        self::assertSame(LawfulBasis::Contract, $set->lawfulBasis);
        self::assertSame(1, $set->retention->days);

        $cookies = SessionPersonalData::cookies();
        self::assertSame([SessionMiddleware::SECURE_COOKIE, SessionMiddleware::COOKIE], array_map(static fn ($cookie) => $cookie->name, $cookies));
        foreach ($cookies as $cookie) {
            self::assertTrue($cookie->strictlyNecessary);
            self::assertNull($cookie->lifetime, 'until the browser closes');
        }
    }

    public function testExportsAndEndsThePersonsSessions(): void
    {
        $directory = new TemporaryDirectory();
        try {
            $store = new FileSessionStore($directory->path . '/sessions', new FixedClock(), 120);
            $store->write('phone', [Session::USER_ID => 7]);
            $store->write('other', [Session::USER_ID => 8]);
            $data = new SessionPersonalData($store);
            $ada = new UserRef(7, 'ada@example.org');

            self::assertSame([['last_seen_at' => '2026-11-14T12:00:00+00:00', 'expires_at' => '2026-11-14T12:02:00+00:00']], [...$data->export($ada)]);
            self::assertSame(1, $data->erase($ada)->deleted);
            self::assertSame([], [...$data->export($ada)]);
            self::assertTrue($data->erase($ada)->isEmpty(), 'safe to run again');
            self::assertNotNull($store->read('other'));
        } finally {
            $directory->remove();
        }
    }

    public function testAStoreThatCantFindAPersonsSessionsHasNothingToGive(): void
    {
        $data = new SessionPersonalData(self::createStub(SessionStore::class));
        $ada = new UserRef(7, 'ada@example.org');

        self::assertSame([], [...$data->export($ada)]);
        self::assertTrue($data->erase($ada)->isEmpty());
    }
}
