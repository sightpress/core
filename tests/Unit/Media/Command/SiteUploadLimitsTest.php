<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Media\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use SightPress\Core\Audit\AuditFilter;
use SightPress\Core\Audit\AuditLog;
use SightPress\Core\Audit\AuditRecorder;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Kernel;
use SightPress\Core\Media\Command\SiteMediaLimitsCommand;
use SightPress\Core\Media\Command\SystemCheckCommand;
use SightPress\Core\Media\MediaLimits;
use SightPress\Core\Media\UploadRefusals;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRegistryFormat;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tests\Support\SiteFixture;
use SightPress\Core\Tests\Support\TestProject;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * Two sites on one tenant with a ceiling: each keeps its own limits in the registry, within the ceiling.
 */
#[CoversClass(SiteMediaLimitsCommand::class)]
#[CoversClass(SystemCheckCommand::class)]
#[CoversClass(SiteRegistryFormat::class)]
#[CoversClass(SiteContainerFactory::class)]
#[CoversClass(Site::class)]
#[CoversClass(AuditRecorder::class)]
#[UsesNamespace('SightPress\Core')]
final class SiteUploadLimitsTest extends TestCase
{
    private const int MB = 1024 * 1024;

    private TestProject $project;

    private Kernel $kernel;

    private string $stdout = '';

    private string $stderr = '';

    protected function setUp(): void
    {
        $this->project = new TestProject();
        $this->project->directory->write('config/tenant.php', '<?php return ' . var_export([
            'log' => ['path' => $this->project->path() . '/var/log/test.log'],
            'uploads' => ['proxy_body_limit' => '65M'],
            'media' => ['limits' => ['max' => ['audio' => '500M']]],
        ], true) . ';');
        $this->boot();
        foreach (['podcast' => 'podcast.example', 'brochure' => 'brochure.example'] as $slug => $domain) {
            self::assertSame(0, $this->console([
                'command' => 'site:create',
                'slug' => $slug,
                '--domain' => $domain,
                '--db' => 'sqlite:///' . $this->project->path() . '/sites/' . $slug . '.sqlite',
                '--owner-type' => 'organization',
                '--admin-email' => 'ada@example.org',
                '--admin-first-name' => 'Ada',
                '--admin-last-name' => 'Lovelace',
            ]), $this->stderr);
        }
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testASiteGetsItsOwnLimitsAndTheOtherKeepsTheDefaults(): void
    {
        self::assertSame(0, $this->console(['command' => 'site:media:limits', 'slug' => 'podcast', '--audio' => '500M', '--image' => '2M']), $this->stderr);
        self::assertStringContainsString('The upload limits of "podcast" have been saved.', $this->stdout);
        self::assertStringContainsString('Audio files: 500 MB (this site)', $this->stdout);
        self::assertStringContainsString('Videos: 1 GB (install default)', $this->stdout);

        $this->boot();
        self::assertSame(500 * self::MB, $this->limits('podcast')->audioBytes);
        self::assertSame(2 * self::MB, $this->limits('podcast')->imageBytes);
        self::assertSame(200 * self::MB, $this->limits('brochure')->audioBytes);

        $entries = Services::get($this->site('podcast'), AuditLog::class)->find(new AuditFilter(), 10);
        self::assertSame('settings.changed', $entries[0]->action);
        self::assertNull($entries[0]->actorId);
        self::assertSame('Changed the site settings: Upload limits.', $entries[0]->summary);
    }

    public function testAValueAboveTheCeilingIsRefusedWhenWritten(): void
    {
        self::assertSame(1, $this->console(['command' => 'site:media:limits', 'slug' => 'podcast', '--audio' => '501M']));
        self::assertStringContainsString('The audio limit of 501 MB is above the most this install allows (500 MB).', $this->stderr);

        self::assertSame(1, $this->console(['command' => 'site:media:limits', 'slug' => 'podcast', '--pixels' => '26000000']));
        self::assertStringContainsString('The pixels limit of 26,000,000 is above the most this install allows (25,000,000).', $this->stderr);

        $this->boot();
        self::assertTrue($this->registered('podcast')->mediaLimits->isEmpty());
    }

    public function testShowsResetsAndRefusesBadInput(): void
    {
        self::assertSame(0, $this->console(['command' => 'site:media:limits', 'slug' => 'podcast']));
        self::assertStringContainsString('Pictures: 20 MB (install default)', $this->stdout);
        self::assertStringContainsString('Pixels in a picture: 25,000,000 (install default)', $this->stdout);

        self::assertSame(1, $this->console(['command' => 'site:media:limits', 'slug' => 'nope']));
        self::assertStringContainsString('There is no site "nope".', $this->stderr);

        self::assertSame(1, $this->console(['command' => 'site:media:limits', 'slug' => 'podcast', '--image' => 'big']));
        self::assertStringContainsString('"image" must be a size', $this->stderr);

        self::assertSame(0, $this->console(['command' => 'site:media:limits', 'slug' => 'podcast', '--image' => '2M']));
        self::assertSame(0, $this->console(['command' => 'site:media:limits', 'slug' => 'podcast', '--image' => '2M']));
        self::assertStringContainsString('Nothing was changed.', $this->stdout);
        self::assertSame(0, $this->console(['command' => 'site:media:limits', 'slug' => 'podcast', '--reset' => true, '--video' => '100M']));
        $this->boot();
        self::assertSame(['video' => 100 * self::MB], $this->registered('podcast')->mediaLimits->toArray());
        self::assertSame(0, $this->console(['command' => 'site:media:limits', 'slug' => 'podcast', '--reset' => true]));
        $this->boot();
        self::assertTrue($this->registered('podcast')->mediaLimits->isEmpty());
        self::assertArrayNotHasKey('media_limits', SiteRegistryFormat::export([$this->registered('podcast')])['sites']['podcast']);
    }

    public function testABadValueInTheRegistryIsNamed(): void
    {
        $registry = SiteRegistryFormat::export([$this->registered('podcast')]);
        foreach (['big', ['image' => 'big']] as $limits) {
            $registry['sites']['podcast']['media_limits'] = $limits;
            try {
                SiteRegistryFormat::parse($registry, 'sites.php');
                self::fail('Expected the registry to be refused.');
            } catch (\SightPress\Core\Tenant\SiteRegistryException $exception) {
                self::assertStringContainsString('"media_limits"', $exception->getMessage());
            }
        }
    }

    public function testSystemCheckUsesTheSitesLimitsAndItsRefusals(): void
    {
        $this->console(['command' => 'site:media:limits', 'slug' => 'podcast', '--document' => '10M']);
        $this->boot();
        Services::get($this->site('podcast'), UploadRefusals::class)->record();

        $this->console(['command' => 'system:check', '--site' => 'podcast']);

        self::assertMatchesRegularExpression('/Documents: \d+ MB/', $this->stdout);
        self::assertStringNotContainsString('the site allows 50 MB', $this->stdout);
        self::assertStringContainsString('Note: The web server refused 1 upload(s) for this site in the last 7 days', $this->stdout);
        self::assertStringNotContainsString('refused 1 upload', $this->stderr);
        self::assertSame(1, $this->console(['command' => 'system:check', '--site' => 'nope']));
        self::assertStringContainsString('There is no site "nope".', $this->stderr);
    }

    public function testRecommendsForTheLargestSiteOrForOne(): void
    {
        $this->console(['command' => 'site:media:limits', 'slug' => 'podcast', '--video' => '100M', '--audio' => '500M']);
        $this->console(['command' => 'site:media:limits', 'slug' => 'brochure', '--video' => '32M', '--audio' => '32M']);
        $this->boot();

        $this->console(['command' => 'system:check', '--recommend' => true]);
        self::assertStringContainsString('Recommended server values for all sites (largest file 1 GB):', $this->stdout);
        self::assertStringContainsString('UPLOAD_MAX_SIZE=1G', $this->stdout);
        self::assertStringContainsString('With chunked uploads', $this->stdout);
        self::assertStringContainsString('UPLOAD_MAX_SIZE=16M', $this->stdout);

        $this->console(['command' => 'system:check', '--site' => 'podcast', '--recommend' => true]);
        self::assertStringContainsString('Recommended server values for "podcast" (largest file 500 MB):', $this->stdout);
        self::assertStringContainsString('UPLOAD_MAX_SIZE=500M', $this->stdout);
    }

    public function testAProbeThatCantConnectIsAProblem(): void
    {
        self::assertSame(1, $this->console(['command' => 'system:check', '--probe-url' => 'ftp://nginx']));
        self::assertStringContainsString('The probe address must start with http://', $this->stderr);
    }

    private function limits(string $slug): MediaLimits
    {
        return Services::get($this->site($slug), MediaLimits::class);
    }

    private function registered(string $slug): Site
    {
        $site = Services::get($this->kernel->container(), SiteRepository::class)->find($slug);
        self::assertNotNull($site);

        return $site;
    }

    private function site(string $slug): ContainerInterface
    {
        return Services::get($this->kernel->container(), SiteContainerFactory::class)->forSite($this->registered($slug));
    }

    private function boot(): void
    {
        $this->kernel = Kernel::boot($this->project->path(), ['APP_ENV' => 'test', 'APP_SECRET' => SiteFixture::SECRET]);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function console(array $input): int
    {
        $application = $this->kernel->console();
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $status = $tester->run($input, ['interactive' => false, 'capture_stderr_separately' => true]);
        $this->stdout = $tester->getDisplay();
        $this->stderr = $tester->getErrorOutput();

        return $status;
    }
}
