<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Rbac;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Rbac\RbacException;
use SightPress\Core\Rbac\RegistrationRole;
use SightPress\Core\Tests\Support\CoreSiteDatabase;

#[CoversClass(RegistrationRole::class)]
#[UsesNamespace('SightPress\Core')]
final class RegistrationRoleTest extends TestCase
{
    public function testDefaultsToMember(): void
    {
        $database = new CoreSiteDatabase();

        self::assertSame('member', $database->registrationRole()->name());
    }

    public function testCanChangeToASafeRole(): void
    {
        $database = new CoreSiteDatabase();
        $database->seeder()->seed();
        $registration = $database->registrationRole();

        $registration->change('contributor');

        self::assertSame('contributor', $registration->name());
        self::assertSame('contributor', $database->settings()->get(RegistrationRole::SETTING));
        $contributor = $database->roles()->findRole('contributor');
        self::assertNotNull($contributor);
        self::assertTrue($registration->is($contributor));
    }

    public function testUnsafeRolesAreRefused(): void
    {
        $database = new CoreSiteDatabase();
        $database->seeder()->seed();
        $database->manager()->create('exporter', 'Exporter', ['audit.view']);

        foreach (['admin', 'editor', 'exporter'] as $name) {
            try {
                $database->registrationRole()->change($name);
                self::fail(sprintf('"%s" became the sign-up role.', $name));
            } catch (RbacException $exception) {
                self::assertStringContainsString('People who sign up can\'t get the "' . $name . '" role', $exception->getMessage());
            }
        }

        self::assertSame('member', $database->registrationRole()->name());
    }

    public function testUnknownRolesAreRefused(): void
    {
        $database = new CoreSiteDatabase();

        $this->expectException(RbacException::class);
        $this->expectExceptionMessage('There is no role called "ghost".');

        $database->registrationRole()->change('ghost');
    }
}
