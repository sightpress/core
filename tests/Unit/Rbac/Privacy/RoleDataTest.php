<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Rbac\Privacy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Privacy\Row;
use SightPress\Core\People\Privacy\InvitationData;
use SightPress\Core\Rbac\Privacy\RoleData;
use SightPress\Core\Tests\Support\Privacy\SiteTables;
use SightPress\Module\Privacy\UserRef;

#[CoversClass(RoleData::class)]
#[CoversClass(InvitationData::class)]
#[UsesNamespace('SightPress\Core')]
final class RoleDataTest extends TestCase
{
    public function testExportsAndDeletesRoles(): void
    {
        $connection = SiteTables::connection();
        $connection->insert('roles', ['id' => 1, 'name' => 'editor', 'label' => 'Editor', 'is_system' => 1]);
        $connection->insert('roles', ['id' => 2, 'name' => 'admin', 'label' => 'Admin', 'is_system' => 1]);
        $connection->insert('user_role', ['user_id' => 7, 'role_id' => 1]);
        $connection->insert('user_role', ['user_id' => 7, 'role_id' => 2]);
        $connection->insert('user_role', ['user_id' => 8, 'role_id' => 1]);
        $data = new RoleData($connection);
        $ada = new UserRef(7, 'ada@example.org');

        self::assertSame('rbac.roles', $data->describe()->key);
        self::assertSame([['role' => 'admin', 'label' => 'Admin'], ['role' => 'editor', 'label' => 'Editor']], iterator_to_array($data->export($ada), false));
        self::assertSame(2, $data->erase($ada)->deleted);
        self::assertSame(1, Row::int($connection->fetchOne('SELECT COUNT(*) FROM user_role')));
    }

    public function testExportsAndDeletesInvitationsSentToTheAddress(): void
    {
        $connection = SiteTables::connection();
        foreach (['ada@example.org', 'grace@example.org'] as $email) {
            $connection->insert('invitations', ['email' => $email, 'role' => 'editor', 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'invited_by' => 1, 'created_at' => '2026-11-01 10:00:00', 'expires_at' => '2026-11-08 10:00:00']);
        }
        $data = new InvitationData($connection);
        $ada = new UserRef(7, 'Ada@Example.org');

        self::assertSame(30, $data->describe()->retention->days);
        self::assertSame([['email' => 'ada@example.org', 'role' => 'editor', 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'sent_at' => '2026-11-01T10:00:00Z', 'expires_at' => '2026-11-08T10:00:00Z', 'accepted_at' => null]], iterator_to_array($data->export($ada), false));
        self::assertSame(1, $data->erase($ada)->deleted);
        self::assertSame(['grace@example.org'], $connection->fetchFirstColumn('SELECT email FROM invitations'));
    }
}
