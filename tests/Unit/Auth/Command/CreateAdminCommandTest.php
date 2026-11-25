<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Auth\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use SightPress\Core\Auth\Command\CreateAdminCommand;
use SightPress\Core\Auth\Contract\VerificationChannel;
use SightPress\Core\Auth\Contract\VerificationCodes;
use SightPress\Core\Auth\Contract\VerificationPurpose;
use SightPress\Core\Auth\PasswordHasher;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Kernel;
use SightPress\Core\Rbac\RoleRepository;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tests\Support\SiteFixture;
use SightPress\Core\Tests\Support\TestProject;
use SightPress\Core\User\UserRepository;
use SightPress\Module\Console\Command;
use Symfony\Component\Console\Tester\ApplicationTester;

#[CoversClass(CreateAdminCommand::class)]
#[UsesNamespace('SightPress\Core')]
final class CreateAdminCommandTest extends TestCase
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

    public function testCreatesAPendingAdminAndPrintsTheirSetupCode(): void
    {
        $status = $this->createAdmin(['--email' => ' Grace@Example.org ', '--first-name' => 'Grace', '--last-name' => 'Hopper']);

        self::assertSame(Command::SUCCESS, $status, $this->stderr);
        $lines = explode("\n", trim($this->stdout));
        self::assertSame('Created the account of Grace Hopper (grace@example.org).', $lines[0]);
        self::assertSame(1, preg_match('#^Give the setup code (\d{3}) (\d{3}) to grace@example\.org\. They go to https://demo\.example/login/setup, .* for 7 days\.$#', $lines[1], $code));

        $services = $this->services();
        $grace = Services::get($services, UserRepository::class)->findByEmail('grace@example.org');
        self::assertNotNull($grace);
        self::assertTrue($grace->isPending());
        self::assertSame('UTC', $grace->timezone(), 'the site\'s time zone');
        self::assertNotNull($grace->avatarPath());
        self::assertSame(['admin'], array_map(static fn ($r) => $r->name(), Services::get($services, RoleRepository::class)->rolesOf((int) $grace->id())));
        $check = Services::get($services, VerificationCodes::class)->check(VerificationPurpose::AccountSetup, VerificationChannel::Email, 'grace@example.org', $code[1] . $code[2], '192.0.2.1');
        self::assertSame($grace->id(), $check->accountId);
    }

    public function testRunningItAgainGivesTheWaitingAccountANewCode(): void
    {
        $status = $this->createAdmin(['--email' => 'ada@example.org', '--first-name' => 'Ada', '--last-name' => 'Lovelace', '--url' => 'http://localhost:8080']);

        self::assertSame(Command::SUCCESS, $status, $this->stderr);
        self::assertStringContainsString('The account of ada@example.org is still waiting for its password: here is a new code.', $this->stdout);
        self::assertStringContainsString('They go to http://localhost:8080/login/setup,', $this->stdout);
    }

    public function testTheOperatorCanGiveTheAdminsMobileNumber(): void
    {
        $status = $this->createAdmin(['--email' => 'grace@example.org', '--first-name' => 'Grace', '--last-name' => 'Hopper', '--phone' => '07400 123456']);

        self::assertSame(Command::SUCCESS, $status, $this->stderr);
        self::assertStringContainsString('Their mobile number is +44•••••••456.', $this->stdout);
        $grace = Services::get($this->services(), UserRepository::class)->findByEmail('grace@example.org');
        self::assertSame(['+447400123456', 'operator'], [$grace?->phone(), $grace?->phoneLookup()]);

        self::assertSame(Command::INVALID, $this->createAdmin(['--email' => 'alan@example.org', '--first-name' => 'Alan', '--last-name' => 'Turing', '--phone' => '+447400123456']));
        self::assertStringContainsString('Another account of the site already has that mobile number.', $this->stderr);
        self::assertSame(Command::INVALID, $this->createAdmin(['--email' => 'alan@example.org', '--first-name' => 'Alan', '--last-name' => 'Turing', '--phone' => '020 7946 0000']));
        self::assertStringContainsString('That isn\'t a mobile number.', $this->stderr);
        self::assertSame(Command::INVALID, $this->createAdmin(['--email' => 'alan@example.org', '--first-name' => 'Alan', '--last-name' => 'Turing', '--phone' => 'none']));
        self::assertSame(Command::SUCCESS, $this->createAdmin(['--email' => 'grace@example.org', '--first-name' => 'Grace', '--last-name' => 'Hopper', '--phone' => '+447400123456']), 'their own number again');
    }

    public function testAnAccountThatSignsInIsLeftAlone(): void
    {
        $users = Services::get($this->services(), UserRepository::class);
        $ada = $users->findByEmail('ada@example.org');
        self::assertNotNull($ada);
        $ada->setPasswordHash(new PasswordHasher(['memory_cost' => 8, 'time_cost' => 1, 'threads' => 1])->hash('a long enough password'), new \DateTimeImmutable());
        $users->save($ada);

        $status = $this->createAdmin(['--email' => 'ada@example.org', '--first-name' => 'Ada', '--last-name' => 'Lovelace']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('There is already an account for ada@example.org that can sign in.', $this->stderr);
    }

    public function testAnUnknownSiteOrAMissingNameIsRefused(): void
    {
        self::assertSame(Command::FAILURE, $this->createAdmin(['--email' => 'g@example.org', '--first-name' => 'G', '--last-name' => 'H'], 'nope'));
        self::assertStringContainsString('There is no site "nope".', $this->stderr);

        self::assertSame(Command::INVALID, $this->createAdmin(['--email' => 'grace', '--first-name' => 'G', '--last-name' => 'H']));
        self::assertStringContainsString('"grace" is not an email address.', $this->stderr);
    }

    /**
     * @param array<string, string> $options
     */
    private function createAdmin(array $options, string $slug = 'demo'): int
    {
        return $this->console(['command' => 'site:user:create-admin', 'slug' => $slug, ...$options]);
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

    private function services(): ContainerInterface
    {
        $kernel = $this->kernel();
        $site = Services::get($kernel->container(), SiteRepository::class)->find('demo');
        self::assertNotNull($site);

        return Services::get($kernel->container(), SiteContainerFactory::class)->forSite($site);
    }
}
