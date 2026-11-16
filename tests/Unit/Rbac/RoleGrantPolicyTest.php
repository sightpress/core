<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Rbac;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Rbac\Contract\RoleChoice;
use SightPress\Core\Rbac\RoleGrantPolicy;
use SightPress\Core\Tests\Support\CoreSiteDatabase;

#[CoversClass(RoleGrantPolicy::class)]
#[CoversClass(RoleChoice::class)]
#[UsesNamespace('SightPress\Core')]
final class RoleGrantPolicyTest extends TestCase
{
    private CoreSiteDatabase $database;

    private RoleGrantPolicy $grants;

    protected function setUp(): void
    {
        $this->database = new CoreSiteDatabase();
        $this->database->seeder()->seed();
        $this->database->manager()->create('volunteer', 'Volunteer', ['page.create']);
        $this->grants = new RoleGrantPolicy($this->database->roles());
    }

    public function testAdminsGiveAnyRoleEditorsOnlyContributorAndOthersNone(): void
    {
        $admin = new Actor(1, ['admin.access', 'user.invite', 'user.manage']);
        $editor = new Actor(2, ['admin.access', 'user.invite']);
        $contributor = new Actor(3, ['admin.access']);

        self::assertSame(['admin', 'editor', 'contributor', 'member', 'volunteer'], self::names($this->grants->grantableBy($admin)));
        self::assertSame(['contributor'], self::names($this->grants->grantableBy($editor)));
        self::assertSame([], self::names($this->grants->grantableBy($contributor)));
        self::assertTrue($this->grants->canGrant($admin, 'admin'));
        self::assertFalse($this->grants->canGrant($admin, 'nobody'));
        self::assertTrue($this->grants->canGrant($editor, 'contributor'));
        self::assertFalse($this->grants->canGrant($editor, 'editor'));
        self::assertFalse($this->grants->canGrant($contributor, 'contributor'));
        self::assertEquals(
            new RoleChoice('contributor', 'Contributor', 'Can write pages and send them for review, but not publish them.'),
            $this->grants->grantableBy($editor)[0],
        );
        self::assertSame('A custom role: Volunteer.', $this->grants->find('volunteer')?->description);
        self::assertNull($this->grants->find('nobody'));
    }

    public function testGivesARoleAndTellsWhichRoleSomeoneHas(): void
    {
        self::assertNull($this->grants->roleOf(7));

        $this->grants->assign(7, 'contributor');
        $this->grants->assign(7, 'editor');

        self::assertSame('editor', $this->grants->roleOf(7)?->name);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('There is no role called "nobody".');
        $this->grants->assign(7, 'nobody');
    }

    /**
     * @param list<RoleChoice> $choices
     *
     * @return list<string>
     */
    private static function names(array $choices): array
    {
        return array_map(static fn (RoleChoice $choice): string => $choice->name, $choices);
    }
}
