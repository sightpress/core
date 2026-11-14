<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Http;

use LogicException;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Http\CurrentRequest;
use SightPress\Core\Http\Security\GrantedPermissions;

#[CoversClass(CurrentRequest::class)]
#[CoversClass(GrantedPermissions::class)]
final class CurrentRequestTest extends TestCase
{
    public function testSwapsTheRequestAndHandsBackThePreviousOne(): void
    {
        $current = new CurrentRequest();
        $first = new ServerRequest('GET', '/one');
        $second = new ServerRequest('GET', '/two');

        self::assertNull($current->swap($first));
        self::assertSame($first, $current->get());
        self::assertSame($first, $current->swap($second));
        self::assertSame($second, $current->swap(null));

        $this->expectException(LogicException::class);
        $current->get();
    }

    public function testPermissionsComeFromTheRequestAndAnonymousHoldsNone(): void
    {
        $request = new ServerRequest('GET', '/');

        self::assertFalse(GrantedPermissions::fromRequest($request)->has('page.publish'));

        $granted = GrantedPermissions::fromRequest(
            $request->withAttribute(GrantedPermissions::class, new GrantedPermissions(['page.publish', 'page.edit'])),
        );
        self::assertTrue($granted->has('page.publish'));
        self::assertFalse($granted->has('user.manage'));
    }
}
