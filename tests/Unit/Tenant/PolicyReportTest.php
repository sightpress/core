<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Tenant;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Kernel;
use SightPress\Core\Tenant\PolicyReport;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tenant\SiteStatus;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Tests\Support\SiteFixture;
use SightPress\Core\Tests\Support\TestProject;
use SightPress\Module\Console\Command;
use Symfony\Component\Console\Tester\ApplicationTester;

#[CoversClass(PolicyReport::class)]
#[UsesNamespace('SightPress\Core')]
final class PolicyReportTest extends TestCase
{
    private TestProject $project;

    protected function setUp(): void
    {
        $this->project = new TestProject();
        foreach (['demo' => 'demo.example.org, old.test', 'docs' => 'docs.example.org', 'gone' => 'gone.test'] as $slug => $domains) {
            $console = $this->kernel()->console();
            $console->setAutoExit(false);
            $status = new ApplicationTester($console)->run([
                'command' => 'site:create',
                'slug' => $slug,
                '--domain' => $domains,
                '--db' => 'sqlite:///' . $this->project->path() . '/sites/' . $slug . '.sqlite',
                '--owner-type' => 'organization',
                '--locale' => 'en-GB',
                '--admin-email' => $slug === 'docs' ? 'ada@example.org' : 'ada@elsewhere.test',
                '--admin-first-name' => 'Ada',
                '--admin-last-name' => 'Lovelace',
            ], ['interactive' => false]);
            self::assertSame(Command::SUCCESS, $status);
        }
        $sites = Services::get($this->kernel()->container(), SiteRepository::class);
        $gone = $sites->find('gone') ?? self::fail();
        $sites->save($gone->withStatus(SiteStatus::Deleted));
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testListsSitesAndAccountsOutsideATightenedPolicy(): void
    {
        self::assertSame([
            'Site "demo" uses old.test, outside domain_policy (example.org and names under it). It keeps working; new domains must follow the policy.',
            'Site "demo" has 1 account with an email address outside email_policy (example.org). They keep signing in; new and changed addresses must follow the policy.',
        ], $this->report(['domain_policy' => ['subdomain_of' => 'example.org'], 'email_policy' => ['domains' => ['example.org']]])->notes());
        self::assertSame([], $this->report(['email_policy' => ['domains' => ['example.org', 'elsewhere.test']]])->notes());
        self::assertSame([], $this->report([])->notes());
    }

    /**
     * @param array<mixed> $tenant
     */
    private function report(array $tenant): PolicyReport
    {
        $container = $this->kernel()->container();

        return new PolicyReport(Services::get($container, SiteRepository::class), Services::get($container, SiteContainerFactory::class), TenantConfig::fromArray($tenant, $this->project->path()));
    }

    private function kernel(): Kernel
    {
        return Kernel::boot($this->project->path(), ['APP_ENV' => 'test', 'APP_SECRET' => SiteFixture::SECRET]);
    }
}
