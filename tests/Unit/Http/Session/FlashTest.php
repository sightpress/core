<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Http\Session;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Http\Session\Session;

#[CoversClass(Flash::class)]
#[UsesClass(Session::class)]
final class FlashTest extends TestCase
{
    public function testMessagesAreShownOnceInTheOrderTheyWereAdded(): void
    {
        $session = new Session();

        Flash::add($session, 'Saved.');
        Flash::add($session, 'Your password has been changed.');

        self::assertSame(['Saved.', 'Your password has been changed.'], Flash::take($session));
        self::assertSame([], Flash::take($session));
        self::assertFalse($session->has(Flash::KEY));
    }

    public function testAnythingElseStoredUnderTheKeyIsIgnored(): void
    {
        $session = new Session(null, [Flash::KEY => 'not a list']);
        self::assertSame([], Flash::take($session));

        $session->set(Flash::KEY, ['kept', 42, ['x']]);
        Flash::add($session, 'added');
        self::assertSame(['kept', 'added'], Flash::take($session));
    }
}
