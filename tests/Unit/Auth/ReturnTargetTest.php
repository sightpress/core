<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Auth\ReturnTarget;
use SightPress\Core\Rbac\Contract\Actor;

#[CoversClass(ReturnTarget::class)]
#[UsesClass(Actor::class)]
final class ReturnTargetTest extends TestCase
{
    /**
     * @return iterable<string, array{list<string>, ?string, string}>
     */
    public static function targets(): iterable
    {
        $staff = ['admin.access'];
        yield 'staff without a page go to the admin panel' => [$staff, null, '/admin'];
        yield 'members without a page go home' => [[], null, '/'];
        yield 'back to a public page' => [[], '/about?tab=team#people', '/about?tab=team#people'];
        yield 'staff back to the admin page' => [$staff, '/admin/pages?sort=title', '/admin/pages?sort=title'];
        yield 'members never go to the admin panel' => [[], '/admin', '/'];
        yield 'nor below it' => [[], '/admin/pages', '/'];
        yield 'nor with a query' => [[], '/admin?x=1', '/'];
        yield 'but a page that only starts like it is fine' => [[], '/administration', '/administration'];
        yield 'another host' => [$staff, '//evil.example/admin', '/admin'];
        yield 'a full address' => [[], 'https://evil.example/', '/'];
        yield 'a backslash trick' => [[], '/\\evil.example', '/'];
        yield 'spaces' => [[], '/a b', '/'];
        yield 'back to sign-in' => [$staff, '/login?return=/x', '/admin'];
        yield 'back to sign-out' => [[], '/logout', '/'];
        yield 'back to a password link' => [[], '/password/reset/abc.def', '/'];
        yield 'back to forgot password' => [$staff, '/password/forgot', '/admin'];
        yield 'a page that only starts like sign-in' => [[], '/login-help', '/login-help'];
        yield 'a page that only starts like the password pages' => [[], '/passwords-explained', '/passwords-explained'];
    }

    /**
     * @param list<string> $permissions
     */
    #[DataProvider('targets')]
    public function testSendsPeopleBackOnlyWhereTheyMayGo(array $permissions, ?string $return, string $expected): void
    {
        self::assertSame($expected, ReturnTarget::for(new Actor(1, $permissions), $return));
    }
}
