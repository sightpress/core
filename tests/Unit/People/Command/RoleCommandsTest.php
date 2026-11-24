<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\People\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Audit\AuditFilter;
use SightPress\Core\Audit\AuditLog;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Kernel;
use SightPress\Core\People\Command\CreateRoleCommand;
use SightPress\Core\People\Command\DeleteRoleCommand;
use SightPress\Core\People\Command\GrantRoleCommand;
use SightPress\Core\People\Command\ListRolesCommand;
use SightPress\Core\People\Command\RevokeRoleCommand;
use SightPress\Core\People\Command\RoleCommand;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tests\Support\SiteFixture;
use SightPress\Core\Tests\Support\TestProject;
use SightPress\Module\Console\Command;
use Symfony\Component\Console\Tester\ApplicationTester;

#[CoversClass(RoleCommand::class)]
#[CoversClass(ListRolesCommand::class)]
#[CoversClass(CreateRoleCommand::class)]
#[CoversClass(GrantRoleCommand::class)]
#[CoversClass(RevokeRoleCommand::class)]
#[CoversClass(DeleteRoleCommand::class)]
#[UsesNamespace('SightPress\Core')]
final class RoleCommandsTest extends TestCase
{
    private TestProject $project;

    private string $stdout = '';

    private string $stderr = '';

    protected function setUp(): void
    {
        $this->project = new TestProject();
        self::assertSame(Command::SUCCESS, $this->console([
            'command' => 'site:create',
            'slug' => 'demo',
            '--domain' => 'demo.example',
            '--db' => 'sqlite:///' . $this->project->path() . '/sites/demo.sqlite',
            '--owner-type' => 'organization',
            '--locale' => 'en-GB',
            '--admin-email' => 'ada@example.org',
            '--admin-first-name' => 'Ada',
            '--admin-last-name' => 'Lovelace',
        ]), $this->stderr);
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testListsTheRoles(): void
    {
        self::assertSame(Command::SUCCESS, $this->console(['command' => 'site:role:list', 'slug' => 'demo']), $this->stderr);

        $lines = explode("\n", trim($this->stdout));
        self::assertSame('admin (Admin): 1 person; built in, can\'t be changed. Permissions: all.', $lines[0]);
        self::assertSame('member (Member): 0 people; built in, can\'t be changed; role of people who sign up. Permissions: none.', $lines[3]);
        self::assertStringStartsWith('editor (Editor): 0 people. Permissions: admin.access, ', $lines[1]);
    }

    public function testCreatesGrantsRevokesAndDeletesARoleAndAuditsIt(): void
    {
        self::assertSame(Command::SUCCESS, $this->console(['command' => 'site:role:create', 'slug' => 'demo', 'role' => 'shop_staff', '--permissions' => 'media.upload, ,menu.manage']), $this->stderr);
        self::assertSame('Created the role shop_staff (Shop staff). Permissions: admin.access, media.upload, menu.manage.', trim($this->stdout));

        self::assertSame(Command::SUCCESS, $this->console(['command' => 'site:role:create', 'slug' => 'demo', 'role' => 'guest', '--label' => 'Guests']), $this->stderr);
        self::assertSame('Created the role guest (Guests). Permissions: none.', trim($this->stdout));

        self::assertSame(Command::SUCCESS, $this->console(['command' => 'site:role:grant', 'slug' => 'demo', 'role' => 'shop_staff', 'permission' => 'page.create']), $this->stderr);
        self::assertSame('The role shop_staff now has page.create. Permissions: admin.access, media.upload, menu.manage, page.create.', trim($this->stdout));
        $this->console(['command' => 'site:role:grant', 'slug' => 'demo', 'role' => 'shop_staff', 'permission' => 'page.create']);
        self::assertSame('The role shop_staff already has page.create.', trim($this->stdout));

        self::assertSame(Command::SUCCESS, $this->console(['command' => 'site:role:revoke', 'slug' => 'demo', 'role' => 'shop_staff', 'permission' => 'menu.manage']), $this->stderr);
        self::assertSame('The role shop_staff no longer has menu.manage. Permissions: admin.access, media.upload, page.create.', trim($this->stdout));
        $this->console(['command' => 'site:role:revoke', 'slug' => 'demo', 'role' => 'shop_staff', 'permission' => 'menu.manage']);
        self::assertSame('The role shop_staff doesn\'t have menu.manage.', trim($this->stdout));

        self::assertSame(Command::SUCCESS, $this->console(['command' => 'site:role:delete', 'slug' => 'demo', 'role' => 'shop_staff']), $this->stderr);
        self::assertSame('Deleted the role shop_staff.', trim($this->stdout));

        $role = Services::get($this->services(), AuditLog::class)->find(new AuditFilter(entityType: 'role'), 20);
        self::assertCount(5, $role);
        self::assertSame([null], array_values(array_unique(array_map(static fn ($entry): ?int => $entry->actorId, $role))), 'the command line');
    }

    public function testRefusalsAreErrors(): void
    {
        self::assertSame(Command::FAILURE, $this->console(['command' => 'site:role:grant', 'slug' => 'demo', 'role' => 'member', 'permission' => 'page.create']));
        self::assertStringContainsString('The Member role never has any permission, so it can’t be changed.', $this->stderr);

        self::assertSame(Command::FAILURE, $this->console(['command' => 'site:role:delete', 'slug' => 'demo', 'role' => 'admin']));
        self::assertStringContainsString('“Admin” is built in, so it can’t be deleted.', $this->stderr);

        self::assertSame(Command::FAILURE, $this->console(['command' => 'site:role:create', 'slug' => 'demo', 'role' => 'Bad Name', '--permissions' => 'acme.nope']));
        self::assertStringContainsString('A role name is 1 to 50 lower-case letters', $this->stderr);
        self::assertStringContainsString('There is no permission called “acme.nope” that this site can use.', $this->stderr);

        self::assertSame(Command::FAILURE, $this->console(['command' => 'site:role:revoke', 'slug' => 'demo', 'role' => 'ghost', 'permission' => 'page.create']));
        self::assertStringContainsString('There is no role called “ghost”. See the roles with site:role:list.', $this->stderr);

        self::assertSame(Command::FAILURE, $this->console(['command' => 'site:role:list', 'slug' => 'nope']));
        self::assertStringContainsString('There is no site "nope". See the sites with site:list.', $this->stderr);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function console(array $input): int
    {
        $application = $this->kernel()->console();
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $status = $tester->run($input, ['interactive' => false, 'capture_stderr_separately' => true]);
        $this->stdout = $tester->getDisplay();
        $this->stderr = $tester->getErrorOutput();

        return $status;
    }

    private function kernel(): Kernel
    {
        return Kernel::boot($this->project->path(), ['APP_ENV' => 'test', 'APP_SECRET' => SiteFixture::SECRET]);
    }

    private function services(): \Psr\Container\ContainerInterface
    {
        $kernel = $this->kernel();
        $site = Services::get($kernel->container(), SiteRepository::class)->find('demo');
        self::assertNotNull($site);

        return Services::get($kernel->container(), SiteContainerFactory::class)->forSite($site);
    }
}
