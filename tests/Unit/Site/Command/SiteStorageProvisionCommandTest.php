<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Site\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Infrastructure\Logging\LoggerFactory;
use SightPress\Core\Infrastructure\Storage\Provisioning\StorageProvisioning;
use SightPress\Core\Kernel;
use SightPress\Core\Site\Command\SiteStorageProvisionCommand;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tests\Support\FakeAws;
use SightPress\Core\Tests\Support\SiteFixture;
use SightPress\Core\Tests\Support\TestProject;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\HttpClient\MockHttpClient;

#[CoversClass(SiteStorageProvisionCommand::class)]
#[UsesNamespace('SightPress\Core')]
final class SiteStorageProvisionCommandTest extends TestCase
{
    private TestProject $project;

    private Kernel $kernel;

    private FakeAws $aws;

    /** @var list<string> */
    private array $out = [];

    /** @var list<string> */
    private array $err = [];

    protected function setUp(): void
    {
        putenv('APP_SECRET=' . SiteFixture::SECRET);
        $_ENV['APP_SECRET'] = SiteFixture::SECRET;
        $this->aws = new FakeAws();
        $this->project = new TestProject();
        $this->kernel = Kernel::boot($this->project->path(), ['APP_ENV' => 'test', 'APP_SECRET' => SiteFixture::SECRET]);
        $console = $this->kernel->console();
        $console->setAutoExit(false);
        $tester = new ApplicationTester($console);
        self::assertSame(0, $tester->run([
            'command' => 'site:create',
            'slug' => 'demo',
            '--domain' => 'demo.example',
            '--db' => 'sqlite:///' . $this->project->path() . '/databases/demo.sqlite',
            '--owner-type' => 'organization',
            '--admin-email' => 'ada@example.org',
            '--admin-first-name' => 'Ada',
            '--admin-last-name' => 'Lovelace',
            '--timezone' => 'Europe/London',
        ], ['interactive' => false, 'capture_stderr_separately' => true]), $tester->getErrorOutput());
    }

    protected function tearDown(): void
    {
        putenv('APP_SECRET');
        unset($_ENV['APP_SECRET']);
        $this->project->remove();
    }

    /**
     * @param array<string, string> $environment
     */
    private function command(array $environment = ['SIGHTPRESS_AWS_ACCESS_KEY_ID' => 'AKIAOPERATOR', 'SIGHTPRESS_AWS_SECRET_ACCESS_KEY' => 'operator-secret']): SiteStorageProvisionCommand
    {
        $container = $this->kernel->container();
        $provisioning = new StorageProvisioning($this->kernel->config(), new MockHttpClient(), static fn (string $name): string|false => $environment[$name] ?? false, ['handler' => $this->aws]);

        return new SiteStorageProvisionCommand(Services::get($container, SiteRepository::class), $provisioning, Services::get($container, SiteContainerFactory::class), Services::get($container, LoggerFactory::class));
    }

    /**
     * @param array<string, string|true> $options
     */
    private function run_(SiteStorageProvisionCommand $command, string $slug, array $options): int
    {
        $input = new class ($slug, $options) implements CommandInput {
            /** @param array<string, string|true> $options */
            public function __construct(private string $slug, private array $options)
            {
            }

            public function argument(string $name): string
            {
                return $this->slug;
            }

            public function option(string $name): ?string
            {
                $value = $this->options[$name] ?? null;

                return is_string($value) ? $value : null;
            }

            public function flag(string $name): bool
            {
                return isset($this->options[$name]);
            }
        };
        $output = new class () implements CommandOutput {
            /** @var list<string> */
            public array $lines = [];

            /** @var list<string> */
            public array $errors = [];

            public function line(string $text = ''): void
            {
                $this->lines[] = $text;
            }

            public function error(string $text): void
            {
                $this->errors[] = $text;
            }
        };
        $status = $command->run($input, $output);
        $this->out = $output->lines;
        $this->err = $output->errors;

        return $status;
    }

    public function testDescribesItself(): void
    {
        self::assertSame('site:storage:provision', $this->command()->name());
        self::assertStringContainsString('--dry-run', $this->command()->description());
        self::assertCount(6, $this->command()->parameters());
    }

    public function testDryRunPrintsThePlanAndChangesNothing(): void
    {
        $registry = file_get_contents($this->project->path() . '/config/sites.php');
        self::assertSame(0, $this->run_($this->command([]), 'demo', ['provider' => 'aws', 'dry-run' => true]), implode("\n", $this->err));
        $text = implode("\n", $this->out);
        self::assertStringContainsString('Location: eu-west-2', $text);
        self::assertStringContainsString('international transfer', $text);
        self::assertStringContainsString('nothing was created', $text);
        self::assertSame([], $this->aws->calls);
        self::assertSame($registry, file_get_contents($this->project->path() . '/config/sites.php'));
    }

    public function testProvisionsThenRunsAgainWithoutCreating(): void
    {
        $this->aws->answers = [
            'HeadBucket' => ['error' => 'NotFound', 'status' => 404],
            'CreateOriginAccessControl' => ['OriginAccessControl' => ['Id' => 'o']],
            'CreateCachePolicy' => ['CachePolicy' => ['Id' => 'c']],
            'CreateDistribution' => ['Distribution' => ['ARN' => 'arn:aws:cloudfront::1:distribution/D', 'DomainName' => 'd.cloudfront.net']],
        ];
        self::assertSame(0, $this->run_($this->command(), 'demo', ['provider' => 'aws']), implode("\n", $this->err));
        $text = implode("\n", $this->out);
        self::assertStringContainsString('Created: bucket, CDN. Already there: nothing.', $text);
        self::assertStringContainsString('https://d.cloudfront.net', $text);
        self::assertStringNotContainsString('AKIAOPERATOR', $text);
        self::assertNull(Services::get($this->kernel->container(), SiteRepository::class)->find('demo')?->storage->s3);

        $reference = FakeAws::dig($this->aws->last('CreateDistribution'), 'DistributionConfig', 'Comment');
        $this->aws->answers = ['ListDistributions' => ['DistributionList' => ['Items' => [['Comment' => $reference, 'ARN' => 'arn:aws:cloudfront::1:distribution/D', 'DomainName' => 'd.cloudfront.net']]]],
            'CreateOriginAccessControl' => ['OriginAccessControl' => ['Id' => 'o']],
            'CreateCachePolicy' => ['CachePolicy' => ['Id' => 'c']],
        ];
        self::assertSame(0, $this->run_($this->command(), 'demo', ['provider' => 'aws']), implode("\n", $this->err));
        self::assertStringContainsString('Created: nothing. Already there: bucket, CDN.', implode("\n", $this->out));
    }

    public function testACloudflareRunWithoutADomainHasNoCdnLine(): void
    {
        $environment = ['SIGHTPRESS_CLOUDFLARE_ACCOUNT_ID' => 'acc', 'SIGHTPRESS_CLOUDFLARE_API_TOKEN' => 't'];
        $container = $this->kernel->container();
        $guard = new \SightPress\Core\Assistant\Http\EndpointGuard($this->kernel->config(), new \SightPress\Core\Tests\Support\Assistant\FixedResolver(['api.cloudflare.com' => ['104.16.132.229']]));
        $http = new MockHttpClient(static fn (): \Symfony\Component\HttpClient\Response\MockResponse => new \Symfony\Component\HttpClient\Response\MockResponse('{"result":{}}'));
        $provisioning = new StorageProvisioning($this->kernel->config(), $http, static fn (string $name): string|false => $environment[$name] ?? false, [], [], $guard, 0);
        $command = new SiteStorageProvisionCommand(Services::get($container, SiteRepository::class), $provisioning, Services::get($container, SiteContainerFactory::class), Services::get($container, LoggerFactory::class));
        self::assertSame(0, $this->run_($command, 'demo', ['provider' => 'cloudflare']), implode("\n", $this->err));
        self::assertStringNotContainsString('CDN will answer', implode("\n", $this->out));
    }

    public function testRefusals(): void
    {
        self::assertSame(1, $this->run_($this->command(), 'nope', ['provider' => 'aws']));
        self::assertStringContainsString('no site', $this->err[0]);
        self::assertSame(1, $this->run_($this->command(), 'demo', ['provider' => 'azure']));
        self::assertStringContainsString('Choose a provider', $this->err[0]);
        self::assertSame(1, $this->run_($this->command([]), 'demo', ['provider' => 'aws']));
        self::assertStringContainsString('SIGHTPRESS_AWS_ACCESS_KEY_ID', $this->err[0]);
    }
}
