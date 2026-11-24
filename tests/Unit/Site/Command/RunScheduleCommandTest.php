<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Site\Command;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Infrastructure\Crypto\SecretBox;
use SightPress\Core\Kernel;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Site\Command\RunScheduleCommand;
use SightPress\Core\Site\ScheduleLock;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tenant\SiteStatus;
use SightPress\Core\Tests\Support\SiteFixture;
use SightPress\Core\Tests\Support\TestProject;
use SightPress\Core\User\UserRepository;
use SightPress\Core\Module\CoreModules;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Tenant\TenantConfigLoader;
use SightPress\Core\Tests\Support\FakeModule;
use SightPress\Core\Tests\Support\Modules\ExampleScheduledJob;
use SightPress\Module\Console\Command;
use SightPress\Module\ModuleRegistrar;
use Symfony\Component\Console\Tester\ApplicationTester;

#[CoversClass(RunScheduleCommand::class)]
#[UsesNamespace('SightPress\Core')]
final class RunScheduleCommandTest extends TestCase
{
    private TestProject $project;

    private string $stdout = '';

    private string $stderr = '';

    /** @var list<ModuleCandidate> */
    private array $modules = [];

    protected function setUp(): void
    {
        $this->project = new TestProject();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testRunsTheJobsOfEveryEnabledSite(): void
    {
        self::assertSame(Command::SUCCESS, $this->console());
        self::assertMatchesRegularExpression('/^Ran the schedule for 0 sites at \d{4}-\d\d-\d\d \d\d:\d\d UTC\.\n$/', $this->stdout);

        $this->console(['command' => 'site:create', 'slug' => 'demo', '--domain' => 'demo.example', '--db' => 'sqlite:///' . $this->project->path() . '/sites/demo.sqlite', '--owner-type' => 'organization', '--locale' => 'en-GB', '--admin-email' => 'ada@example.org', '--admin-first-name' => 'Ada', '--admin-last-name' => 'Lovelace']);
        $services = Services::get($this->kernel()->container(), SiteContainerFactory::class)->forSite($this->site('demo'));
        $page = Services::get($services, PageEditor::class)->create('en-GB', new PageContent('Concert'));
        $admin = Services::get($services, UserRepository::class)->findByEmail('ada@example.org');
        $page->changeState(PageState::Approved, new DateTimeImmutable());
        $page->schedule(new DateTimeImmutable('-1 minute'), null, $admin?->id(), new DateTimeImmutable());
        Services::get($services, PageRepository::class)->flush();
        $this->register('off', SiteStatus::Disabled, SiteFixture::secretBox()->encrypt('sqlite:///x'));

        self::assertSame(Command::SUCCESS, $this->console());
        self::assertMatchesRegularExpression('/^demo: Published “Concert” on schedule\.\ndemo: Site search: indexed \d+ documents\.\nRan the schedule for 1 site at .+ UTC\.\n$/', $this->stdout);
        self::assertSame(Command::SUCCESS, $this->console());
        self::assertStringStartsWith('Ran the schedule for 1 site', $this->stdout, 'nothing twice');

        $this->register('broken', SiteStatus::Enabled, new SecretBox('another-secret-0123456789abcdef0123456789')->encrypt('sqlite:///x'));
        self::assertSame(Command::FAILURE, $this->console());
        self::assertStringStartsWith('broken: the scheduled jobs failed: ', $this->stderr);
        self::assertStringEndsWith("; 1 failed.\n", $this->stdout);
    }

    public function testRunsTheJobsOfModulesAndReportsTheOnesThatFail(): void
    {
        $this->modules = [new ModuleCandidate(new FakeModule('acme.events', register: static fn (ModuleRegistrar $r) => $r->schedule()->add(ExampleScheduledJob::class)), 'acme/events', '*')];
        $this->console(['command' => 'site:create', 'slug' => 'demo', '--domain' => 'demo.example', '--db' => 'sqlite:///' . $this->project->path() . '/sites/demo.sqlite', '--owner-type' => 'organization', '--locale' => 'en-GB', '--admin-email' => 'ada@example.org', '--admin-first-name' => 'Ada', '--admin-last-name' => 'Lovelace']);

        self::assertSame(Command::SUCCESS, $this->console());
        self::assertMatchesRegularExpression('/^demo: Checked reminders at \d\d:\d\d\.$/m', $this->stdout);

        ExampleScheduledJob::$fail = true;
        try {
            self::assertSame(Command::FAILURE, $this->console());
        } finally {
            ExampleScheduledJob::$fail = false;
        }
        self::assertSame('demo: the job ' . ExampleScheduledJob::class . ' of module "acme.events" failed: The reminder service is down.' . "\n", $this->stderr);
        self::assertStringEndsWith("; 1 failed.\n", $this->stdout);
    }

    public function testNeverRunsTwiceAtOnce(): void
    {
        $lock = new ScheduleLock($this->project->path() . '/' . RunScheduleCommand::LOCK_FILE);
        self::assertTrue($lock->acquire());

        self::assertSame(Command::SUCCESS, $this->console());
        self::assertSame("Another run is still going; this one stops.\n", $this->stdout);
        $lock->release();

        unlink($this->project->path() . '/' . RunScheduleCommand::LOCK_FILE);
        mkdir($this->project->path() . '/' . RunScheduleCommand::LOCK_FILE, 0o775, true);
        self::assertSame(Command::FAILURE, $this->console());
        self::assertStringContainsString('can\'t be opened', $this->stderr);
        self::assertSame('Does what is due on every site, such as publishing scheduled pages. Run it once a minute.', $this->kernel()->console()->find('schedule:run')->getDescription());
    }

    /**
     * @param array<string, mixed> $input
     */
    private function console(array $input = ['command' => 'schedule:run']): int
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
        if ($this->modules === []) {
            return Kernel::boot($this->project->path(), ['APP_ENV' => 'test', 'APP_SECRET' => SiteFixture::SECRET]);
        }

        return Kernel::fromConfig(
            new TenantConfigLoader()->load($this->project->path(), ['APP_ENV' => 'test', 'APP_SECRET' => SiteFixture::SECRET]),
            [...CoreModules::candidates(), ...$this->modules],
        );
    }

    private function site(string $slug): Site
    {
        return Services::get($this->kernel()->container(), SiteRepository::class)->find($slug) ?? throw new \LogicException('No site ' . $slug);
    }

    private function register(string $slug, SiteStatus $status, string $encryptedDsn): void
    {
        $site = SiteFixture::site($slug, [$slug . '.example'], $status);
        Services::get($this->kernel()->container(), SiteRepository::class)->save(new Site(
            $site->id,
            $site->slug,
            $site->name,
            $status,
            $site->ownerType,
            $site->domains,
            $encryptedDsn,
            $site->storage,
            $site->defaultLocale,
            $site->locales,
            $site->defaultTimezone,
            $site->createdAt,
        ));
    }
}
