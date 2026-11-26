<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Media\Command;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Http\Upload\ServerLimits;
use SightPress\Core\Http\Upload\ServerLimitsCheck;
use SightPress\Core\Http\Upload\UploadProbe;
use SightPress\Core\Http\Upload\UploadProbeClient;
use SightPress\Core\Infrastructure\Clock\FrozenClock;
use SightPress\Core\Infrastructure\Crypto\SecretBox;
use SightPress\Core\Media\Command\SystemCheckCommand;
use SightPress\Core\Module\ModuleCatalogue;
use SightPress\Core\Module\ModulePolicy;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Tenant\SiteMigrator;
use SightPress\Core\Tenant\SiteModules;
use SightPress\Core\Media\MediaLimits;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Tests\Support\ArrayCommandInput;
use SightPress\Core\Tests\Support\ArrayContainer;
use SightPress\Core\Tests\Support\RecordingOutput;
use SightPress\Core\Tests\Support\ServerLimitsFixture;
use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandParameter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(SystemCheckCommand::class)]
#[UsesNamespace('SightPress\Core\Http\Upload')]
#[UsesNamespace('SightPress\Core\Media')]
#[UsesNamespace('SightPress\Core\Template')]
#[UsesClass(\SightPress\Core\Infrastructure\Clock\FrozenClock::class)]
#[UsesClass(\SightPress\Core\Infrastructure\Crypto\SecretBox::class)]
#[UsesClass(\SightPress\Core\Tenant\Environment::class)]
#[UsesClass(\SightPress\Core\Tenant\LocaleCode::class)]
#[UsesClass(\SightPress\Core\Tenant\SiteContainerFactory::class)]
#[UsesClass(\SightPress\Core\Tenant\SiteMediaLimits::class)]
#[UsesClass(\SightPress\Core\Tenant\TenantAssistant::class)]
#[UsesClass(\SightPress\Core\Tenant\TenantConfig::class)]
#[UsesClass(\SightPress\Core\Tenant\TenantMediaLimits::class)]
#[UsesClass(\SightPress\Core\Tenant\TenantThemes::class)]
#[UsesClass(\SightPress\Core\Tenant\TenantPolicies::class)]
#[UsesClass(\SightPress\Core\Tenant\TenantSms::class)]
#[UsesClass(\SightPress\Core\Tenant\TwoStepPolicy::class)]
#[UsesClass(\SightPress\Core\Tenant\PolicyReport::class)]
#[UsesClass(\SightPress\Core\Tenant\DomainName::class)]
#[UsesClass(\SightPress\Core\Tenant\Site::class)]
#[UsesClass(\SightPress\Core\Tenant\SiteStorage::class)]
#[UsesClass(SiteModules::class)]
#[UsesClass(SiteMigrator::class)]
#[UsesClass(ModulePolicy::class)]
#[UsesClass(ModuleCatalogue::class)]
#[UsesClass(ModuleRegistry::class)]
final class SystemCheckCommandTest extends TestCase
{
    public function testDescribesItself(): void
    {
        $command = $this->command(ServerLimitsFixture::standard());

        self::assertSame('system:check', $command->name());
        self::assertStringStartsWith('Checks the server\'s upload and input limits', $command->description());
        self::assertSame(['site', 'probe-url', 'recommend'], array_map(static fn (CommandParameter $parameter): string => $parameter->name, $command->parameters()));
    }

    public function testTheRecommendedValuesPass(): void
    {
        $output = new RecordingOutput();

        self::assertSame(Command::SUCCESS, $this->command(ServerLimitsFixture::standard())->run(new ArrayCommandInput(), $output));
        self::assertSame([
            'Upload limits',
            '  Largest file in one request: 64 MB',
            '    upload_max_filesize 64 MB, post_max_size 65 MB, web server 65 MB',
            '  Pictures: 20 MB',
            '  Videos: 64 MB (the site allows 1 GB)',
            '  Audio files: 64 MB (the site allows 200 MB)',
            '  Documents: 50 MB',
            '  memory_limit 256 MB, max_input_vars 5000, max_file_uploads 20',
            '  Folder for files being uploaded: /tmp (10 GB free)',
            'Note: The site accepts audio files up to 200 MB, but the server’s settings limit them to 64 MB. Larger ones will be possible once files can be sent in parts.',
            'Note: The site accepts videos up to 1 GB, but the server’s settings limit them to 64 MB. Larger ones will be possible once files can be sent in parts.',
            'No problems found.',
        ], $output->lines);
        self::assertSame([], $output->errors);
    }

    public function testProblemsFailTheCheck(): void
    {
        $output = new RecordingOutput();

        self::assertSame(Command::FAILURE, $this->command(ServerLimitsFixture::phpDefaults())->run(new ArrayCommandInput(), $output));
        self::assertContains('    upload_max_filesize 2 MB, post_max_size 8 MB, web server not stated', $output->lines);
        self::assertCount(3, $output->errors);
        self::assertStringStartsWith('Files larger than 2 MB can’t be uploaded', $output->errors[0]);
        self::assertNotContains('No problems found.', $output->lines);
    }

    public function testUnlimitedAndUnknownValuesAreSaidInWords(): void
    {
        $output = new RecordingOutput();
        $this->command(ServerLimitsFixture::standard(upload: null, post: null, memory: null, proxy: null, tempFree: null))->run(new ArrayCommandInput(), $output);

        self::assertContains('  Largest file in one request: no limit', $output->lines);
        self::assertContains('  memory_limit no limit, max_input_vars 5000, max_file_uploads 20', $output->lines);
        self::assertContains('  Folder for files being uploaded: /tmp (free space unknown)', $output->lines);

        $output = new RecordingOutput();
        $this->command(ServerLimitsFixture::standard(tempWritable: false, tempFree: null))->run(new ArrayCommandInput(), $output);
        self::assertContains('  Folder for files being uploaded: /tmp (missing or not writable)', $output->lines);
    }

    public function testTheProbeConfirmsTheWebServersLimit(): void
    {
        $output = new RecordingOutput();

        $status = $this->command(ServerLimitsFixture::standard(), 65)->run(new ArrayCommandInput([], ['probe-url' => 'http://nginx']), $output);

        self::assertSame(Command::SUCCESS, $status);
        self::assertContains('Probe: the web server accepts 65 MB and refuses 66 MB.', $output->lines);
    }

    public function testTheProbeSaysWhenTheStatedLimitIsWrong(): void
    {
        $output = new RecordingOutput();

        $status = $this->command(ServerLimitsFixture::standard(), 10)->run(new ArrayCommandInput([], ['probe-url' => 'http://nginx']), $output);

        self::assertSame(Command::FAILURE, $status);
        self::assertContains('Probe: the web server accepts 10 MB and refuses 11 MB.', $output->lines);
        self::assertStringContainsString('The web server refuses requests of 11 MB, but uploads.proxy_body_limit', $output->errors[0]);
    }

    public function testTheProbeSaysWhenNothingWasRefused(): void
    {
        $output = new RecordingOutput();

        $status = $this->command(ServerLimitsFixture::standard(), 1000)->run(new ArrayCommandInput([], ['probe-url' => 'http://nginx']), $output);

        self::assertSame(Command::SUCCESS, $status);
        self::assertContains('Probe: the web server accepted every size tried, up to 66 MB.', $output->lines);
    }

    public function testSitesOutsideATightenedPolicyAreNotesNotProblems(): void
    {
        $output = new RecordingOutput();
        $sites = self::createStub(SiteRepository::class);
        $sites->method('all')->willReturn([\SightPress\Core\Tests\Support\SiteFixture::site('old', ['old.test'])]);

        $status = $this->command(ServerLimitsFixture::standard(), sites: $sites, tenant: ['domain_policy' => ['subdomain_of' => 'example.org']])->run(new ArrayCommandInput(), $output);

        self::assertSame(Command::SUCCESS, $status);
        self::assertContains('Note: Site "old" uses old.test, outside domain_policy (example.org and names under it). It keeps working; new domains must follow the policy.', $output->lines);
        self::assertSame('No problems found.', $output->lines[array_key_last($output->lines)]);
    }

    /**
     * @param array<mixed> $tenant
     */
    private function command(ServerLimits $server, int $webServerMegabytes = 65, ?SiteRepository $sites = null, array $tenant = []): SystemCheckCommand
    {
        $sites ??= self::createStub(SiteRepository::class);
        $config = TenantConfig::fromArray($tenant, '/tmp/sightpress-test');
        $secretBox = new SecretBox(str_repeat('k', 32));
        $containers = new SiteContainerFactory(new ArrayContainer([]), $secretBox, $config);

        $modules = new SiteModules(new ModulePolicy(new ModuleCatalogue([])), new SiteMigrator($containers, new ModuleRegistry()), $containers, new FrozenClock(new DateTimeImmutable('2026-11-23T12:00:00Z')));

        return new SystemCheckCommand($server, new MediaLimits(), new ServerLimitsCheck(new MediaLimits(), $server), $sites, $containers, $config, new UploadProbeClient(new MockHttpClient(static function (string $method, string $url) use ($webServerMegabytes): MockResponse {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            return (int) $query['bytes'] <= $webServerMegabytes * ServerLimitsFixture::MB
                ? new MockResponse('', ['http_code' => 204, 'response_headers' => [UploadProbe::ANSWER_HEADER . ': accepted']])
                : new MockResponse('', ['http_code' => 413]);
        }), new UploadProbe(str_repeat('s', 32), new FrozenClock(new DateTimeImmutable('2026-11-23T12:00:00Z')))), $modules, new \SightPress\Core\Tenant\PolicyReport($sites, $containers, $config));
    }
}
