<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Rbac\Contract;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Rbac\Contract\Actor;

#[CoversClass(Actor::class)]
final class ActorTest extends TestCase
{
    public function testASignedInActorHoldsItsPermissions(): void
    {
        $actor = new Actor(3, ['page.publish', 'admin.access', 'page.publish']);

        self::assertTrue($actor->isSignedIn());
        self::assertTrue($actor->has('page.publish'));
        self::assertFalse($actor->has('role.manage'));
        self::assertSame(['admin.access', 'page.publish'], $actor->permissions());
        self::assertTrue($actor->is(3));
        self::assertFalse($actor->is(4));
        self::assertFalse($actor->is(null));
    }

    public function testAnAnonymousActorHoldsNothing(): void
    {
        $actor = new Actor(null, ['admin.access']);

        self::assertFalse($actor->isSignedIn());
        self::assertSame([], $actor->permissions());
        self::assertFalse($actor->is(null));
        self::assertEquals(Actor::anonymous(), $actor);
    }

    public function testTravelsOnTheRequest(): void
    {
        $request = new ServerRequest('GET', '/');
        $actor = new Actor(1);

        self::assertFalse(Actor::fromRequest($request)->isSignedIn());
        self::assertSame($actor, Actor::fromRequest($request->withAttribute(Actor::class, $actor)));
    }
}
