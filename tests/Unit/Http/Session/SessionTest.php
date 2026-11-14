<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Http\Session;

use LogicException;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Http\Session\Session;

#[CoversClass(Session::class)]
final class SessionTest extends TestCase
{
    public function testStoresPlainValues(): void
    {
        $session = new Session();

        self::assertNull($session->id());
        self::assertFalse($session->has('user'));
        self::assertSame('none', $session->get('user', 'none'));

        $session->set('user', 42);
        $session->set('flash', ['saved']);
        $session->remove('flash');

        self::assertTrue($session->has('user'));
        self::assertSame(42, $session->get('user'));
        self::assertSame(['user' => 42], $session->all());
    }

    public function testANewIdIsRandomAndCookieSafe(): void
    {
        $session = new Session();
        $id = $session->idOrNew();

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $id);
        self::assertSame($id, $session->idOrNew());
        self::assertNotSame($id, new Session()->idOrNew());
    }

    public function testABagIsChangedInPlace(): void
    {
        $session = new Session('id', ['tokens' => 'not a list']);

        $bag = &$session->bag('tokens');
        $bag['a'] = 1;
        $again = &$session->bag('tokens');
        $again['b'] = 2;

        self::assertSame(['tokens' => ['a' => 1, 'b' => 2]], $session->all());
    }

    public function testRegeneratingKeepsTheDataButNotTheId(): void
    {
        $session = new Session('old', ['user' => 1]);

        $session->regenerate();
        $session->regenerate();

        self::assertNull($session->id());
        self::assertSame('old', $session->previousId());
        self::assertSame(['user' => 1], $session->all());
    }

    public function testInvalidatingForgetsEverything(): void
    {
        $session = new Session('old', ['user' => 1]);

        $session->invalidate();

        self::assertSame([], $session->all());
        self::assertSame('old', $session->previousId());
    }

    public function testComesFromTheRequest(): void
    {
        $session = new Session();

        self::assertSame($session, Session::fromRequest(new ServerRequest('GET', '/')->withAttribute(Session::class, $session)));

        $this->expectException(LogicException::class);
        Session::fromRequest(new ServerRequest('GET', '/'));
    }
}
