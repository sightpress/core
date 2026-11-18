<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Site\Command;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Audit\AuditFilter;
use SightPress\Core\Audit\AuditLog;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Kernel;
use SightPress\Core\Site\Command\AddSiteLocaleCommand;
use SightPress\Core\Site\Command\DefaultSiteLocaleCommand;
use SightPress\Core\Site\Command\RemoveSiteLocaleCommand;
use SightPress\Core\Site\Command\SiteLocaleCommand;
use SightPress\Core\Site\Contract\SiteProfile;
use SightPress\Core\Site\Contract\SiteSettings;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tests\Support\SiteFixture;
use SightPress\Core\Tests\Support\TestProject;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\UserRepository;
use SightPress\Module\Console\Command;
use Symfony\Component\Console\Tester\ApplicationTester;

#[CoversClass(SiteLocaleCommand::class)]
#[CoversClass(AddSiteLocaleCommand::class)]
#[CoversClass(RemoveSiteLocaleCommand::class)]
#[CoversClass(DefaultSiteLocaleCommand::class)]
#[UsesNamespace('SightPress\Core')]
final class SiteLocaleCommandsTest extends TestCase
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

    public function testAddsALanguageToTheSiteAndTheRegistry(): void
    {
        self::assertSame(Command::SUCCESS, $this->console(['command' => 'site:locale:add', 'slug' => 'demo', 'locale' => 'pt_pt']), $this->stderr);

        self::assertSame(
            "Added European Portuguese (pt-PT). Its pages are at addresses starting with /pt-pt.\nThe site \"demo\" is written in: British English (en-GB, main), European Portuguese (pt-PT).\n",
            $this->stdout,
        );
        self::assertSame(['en-GB', 'pt-PT'], $this->site()->locales);
        self::assertSame(['en-GB', 'pt-PT'], $this->inside()->locales);
        $entry = Services::get($this->services(), AuditLog::class)->find(new AuditFilter(), 1)[0];
        self::assertSame(['settings.changed', null], [$entry->action, $entry->actorId]);

        self::assertSame(Command::FAILURE, $this->console(['command' => 'site:locale:add', 'slug' => 'demo', 'locale' => 'pt-PT']));
        self::assertStringContainsString('The site is already written in European Portuguese (pt-PT).', $this->stderr);
    }

    public function testRemovesALanguageOnlyWhenConfirmed(): void
    {
        $this->console(['command' => 'site:locale:add', 'slug' => 'demo', 'locale' => 'es']);
        $users = Services::get($this->services(), UserRepository::class);
        $users->save(new User('bea@example.org', 'Bea', 'Ruiz', 'UTC', new DateTimeImmutable(), locale: 'es'));

        self::assertSame(Command::FAILURE, $this->console(['command' => 'site:locale:remove', 'slug' => 'demo', 'locale' => 'es']));
        self::assertStringContainsString('Run the command again with --yes to remove it.', $this->stderr);
        self::assertSame(['en-GB', 'es'], $this->site()->locales);

        self::assertSame(Command::SUCCESS, $this->console(['command' => 'site:locale:remove', 'slug' => 'demo', 'locale' => 'es', '--yes' => true]), $this->stderr);
        self::assertStringStartsWith('Removed Spanish (es). Its translations are kept', $this->stdout);
        self::assertSame(['en-GB'], $this->site()->locales);
        self::assertNull(Services::get($this->services(), UserRepository::class)->findByEmail('bea@example.org')?->locale());

        self::assertSame(Command::FAILURE, $this->console(['command' => 'site:locale:remove', 'slug' => 'demo', 'locale' => 'en-GB', '--yes' => true]));
        self::assertStringContainsString('is the main language, so it can’t be removed', $this->stderr);
    }

    public function testMakesAnotherLanguageTheMainOneAndWarnsAboutAddresses(): void
    {
        $this->console(['command' => 'site:locale:add', 'slug' => 'demo', 'locale' => 'cy']);

        self::assertSame(Command::SUCCESS, $this->console(['command' => 'site:locale:default', 'slug' => 'demo', 'locale' => 'cy']), $this->stderr);
        self::assertStringContainsString('Welsh (cy) is now the main language', $this->stdout);
        self::assertStringContainsString('Warning: the pages in British English (en-GB) moved to addresses starting with /en-gb.', $this->stdout);
        self::assertSame(['cy', ['cy', 'en-GB']], [$this->site()->defaultLocale, $this->site()->locales]);
        self::assertSame('cy', Services::get($this->services(), SiteSettings::class)->get(SiteProfile::DEFAULT_LOCALE));

        self::assertSame(Command::SUCCESS, $this->console(['command' => 'site:locale:default', 'slug' => 'demo', 'locale' => 'cy']));
        self::assertSame("Welsh (cy) is already the main language.\n", $this->stdout);
    }

    public function testCanWriteTheStarterPagesInTheNewLanguageAsDrafts(): void
    {
        self::assertSame(Command::SUCCESS, $this->console(['command' => 'site:locale:add', 'slug' => 'demo', 'locale' => 'pt_pt', '--starter-pages' => true]), $this->stderr);
        self::assertStringContainsString('Wrote the starter pages in European Portuguese (pt-PT) as drafts: Bem-vindo a demo, Sobre nós,', $this->stdout);
        self::assertStringNotContainsString('Warning', $this->stdout);

        $this->console(['command' => 'site:locale:add', 'slug' => 'demo', 'locale' => 'cy', '--starter-pages' => true]);
        self::assertStringContainsString('Warning: there are no starter pages in Welsh (cy) yet, so they are in British English (en-GB).', $this->stdout);

        $this->console(['command' => 'site:locale:remove', 'slug' => 'demo', 'locale' => 'cy', '--yes' => true]);
        $this->console(['command' => 'site:locale:add', 'slug' => 'demo', 'locale' => 'cy', '--starter-pages' => true]);
        self::assertStringContainsString('The starter pages are already written in Welsh (cy).', $this->stdout, 'a language added again keeps its pages');
    }

    public function testRefusesUnknownSites(): void
    {
        self::assertSame(Command::FAILURE, $this->console(['command' => 'site:locale:add', 'slug' => 'nope', 'locale' => 'es']));
        self::assertStringContainsString('There is no site "nope".', $this->stderr);
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

    private function site(): Site
    {
        return Services::get($this->kernel()->container(), SiteRepository::class)->find('demo') ?? throw new \LogicException('No site');
    }

    private function services(): \Psr\Container\ContainerInterface
    {
        return Services::get($this->kernel()->container(), SiteContainerFactory::class)->forSite($this->site());
    }

    private function inside(): Site
    {
        return Services::get($this->services(), Site::class);
    }
}
