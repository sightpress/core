<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Sms\Command;

use DI\Container;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Audit\AuditFilter;
use SightPress\Core\Audit\AuditLog;
use SightPress\Core\Cli\KeyInput;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Sms\Command\DisableSmsCommand;
use SightPress\Core\Sms\Command\EnableSmsCommand;
use SightPress\Core\Sms\Command\RemoveSmsCommand;
use SightPress\Core\Sms\Command\SetSmsCommand;
use SightPress\Core\Sms\Command\SiteSmsCommand;
use SightPress\Core\Sms\Command\TestSmsCommand;
use SightPress\Core\Sms\SmsSettings;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteModules;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tests\Support\ArrayCommandInput;
use SightPress\Core\Tests\Support\KernelSite;
use SightPress\Core\Tests\Support\RecordingOutput;
use SightPress\Core\Tests\Support\TemporaryDirectory;
use SightPress\Module\Console\Command;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[CoversClass(SiteSmsCommand::class)]
#[CoversClass(SetSmsCommand::class)]
#[CoversClass(RemoveSmsCommand::class)]
#[CoversClass(EnableSmsCommand::class)]
#[CoversClass(DisableSmsCommand::class)]
#[CoversClass(TestSmsCommand::class)]
#[CoversClass(\SightPress\Core\Sms\Contract\TextMessageTested::class)]
#[UsesNamespace('SightPress\Core')]
final class SmsCommandsTest extends TestCase
{
    private const string KEY = 'canary-5f2b9c1d7e4a';

    private const string ACCOUNT = 'AC' . '0123456789abcdef0123456789abcdef';

    private KernelSite $site;

    private TemporaryDirectory $directory;

    /** @var list<MockResponse> */
    private array $answers = [];

    protected function setUp(): void
    {
        $this->boot();
        $this->directory = new TemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->site->remove();
        $this->directory->remove();
    }

    /**
     * @param array<string, mixed> $tenant
     */
    private function boot(array $tenant = []): void
    {
        $this->site = new KernelSite('sms_commands', tenant: $tenant);
        $container = $this->site->kernel->container();
        self::assertInstanceOf(Container::class, $container);
        $container->set(HttpClientInterface::class, new MockHttpClient(fn (): MockResponse => array_shift($this->answers) ?? new MockResponse('{"sid":"SM1","num_segments":"1"}', ['http_code' => 201])));
    }

    public function testEveryCommandNeedsASite(): void
    {
        foreach ([$this->set(), $this->command(RemoveSmsCommand::class), $this->enable(), $this->disable(), $this->command(TestSmsCommand::class)] as $command) {
            self::assertSame([1, ['There is no site "shop". See the sites with site:list.']], self::errors($this->execute($command, [], 'shop')));
            self::assertNotSame('', $command->description());
        }
        self::assertSame([1, ['Text messages are off for the site "demo". Turn them on with: site:sms:enable demo']], self::errors($this->execute($this->command(TestSmsCommand::class), ['to' => '+447400123456'])));
    }

    public function testSetsUpTheProviderReadingTheKeyFromStandardInput(): void
    {
        $set = $this->set($this->keyFile(self::KEY . "\nsecond line\n"));
        self::assertSame(['site:sms:set', 7], [$set->name(), count($set->parameters())]);
        [$code, $output] = $this->execute($set, ['provider' => 'twilio', 'account' => self::ACCOUNT, 'sender' => 'SightPress', 'budget' => '200'], flags: ['key-stdin']);

        self::assertSame(0, $code);
        self::assertSame(['The site "demo" now sends text messages with Twilio.', 'Key: ••••7e4a', 'Turn text messages on with: site:sms:enable demo'], $output->lines);
        $settings = Services::get($this->site->container(), SmsSettings::class);
        self::assertSame([self::KEY, 'twilio', self::ACCOUNT, 'SightPress', 200], [$settings->key(), $settings->provider(), $settings->account(), $settings->sender(), $settings->budget()]);
        $audit = Services::get($this->site->container(), AuditLog::class)->find(new AuditFilter(), 10);
        self::assertStringNotContainsString(self::KEY, serialize($audit));
        self::assertStringContainsString('Text message provider key', implode("\n", array_map(static fn ($entry): string => $entry->summary, $audit)));

        self::assertSame('Nothing changed for "demo".', $this->execute($this->set(), ['provider' => 'twilio', 'account' => self::ACCOUNT, 'sender' => 'SightPress', 'budget' => '200'])[1]->lines[0]);
        [, $output] = $this->execute($this->set(), ['provider' => 'vonage', 'account' => 'abcd1234']);
        self::assertSame(['The site "demo" now sends text messages with Vonage.', 'Key: ••••7e4a', 'Turn text messages on with: site:sms:enable demo'], $output->lines);
    }

    public function testRefusesWhatItCannotSet(): void
    {
        self::assertSame([2, ['There is no text message provider "acme". Choose one of: twilio, vonage.']], self::errors($this->execute($this->set(), ['provider' => 'acme'])));
        self::assertSame([2, ['Give --budget as a whole number of message parts, such as 500 (0 = no limit).']], self::errors($this->execute($this->set(), ['provider' => 'twilio', 'budget' => 'lots'])));
        self::assertSame(2, $this->execute($this->set(), ['provider' => 'twilio', 'sender' => 'Not a valid sender'])[0]);
        self::assertSame([2, ['No key was read from standard input. Pipe it in, for example: printf %s "$KEY" | bin/sightpress site:sms:set …']], self::errors($this->execute($this->set(), ['provider' => 'twilio'], flags: ['key-stdin'])));
        [$code, $output] = $this->execute($this->set(), ['provider' => 'twilio']);
        self::assertSame([0, ['The site "demo" now sends text messages with Twilio.', 'The site has no key yet.', 'Not ready yet: There is no key for Twilio yet.']], [$code, $output->lines]);
    }

    public function testTurnsTextMessagesOnAndOffAndSendsATest(): void
    {
        self::assertSame(['site:sms:enable', 'site:sms:disable'], [$this->enable()->name(), $this->disable()->name()]);
        [$code, $output] = $this->execute($this->enable(), []);
        self::assertSame(1, $code);
        self::assertStringStartsWith('Text messages can’t be turned on yet: No text message provider', $output->errors[0]);

        $this->execute($this->set($this->keyFile(self::KEY)), ['provider' => 'twilio', 'account' => self::ACCOUNT, 'sender' => 'SightPress'], flags: ['key-stdin']);
        self::assertSame([0, ['Text messages are on for "demo".']], self::lines($this->execute($this->enable(), [])));
        self::assertSame([0, ['Text messages were already on for "demo".']], self::lines($this->execute($this->enable(), [])));
        $this->evict();

        $test = $this->command(TestSmsCommand::class);
        self::assertSame(['site:sms:test', 2], [$test->name(), count($test->parameters())]);
        self::assertSame([0, ['The test message is on its way to +44•••••••456 (1 part).']], self::lines($this->execute($test, ['to' => '07400 123456'])));
        $this->answers = [new MockResponse('{"sid":"SM2","num_segments":"2"}', ['http_code' => 201]), new MockResponse('{"code":21408}', ['http_code' => 400])];
        self::assertSame([0, ['The test message is on its way to +44•••••••456 (2 parts).']], self::lines($this->execute($test, ['to' => '+447400123456'])));
        self::assertSame([1, ['The test message wasn\'t sent to +44•••••••456: Text messages can’t be sent to numbers in this country from this site.']], self::errors($this->execute($test, ['to' => '+447400123456'])));
        self::assertSame([2, ['That isn\'t a phone number. Give it with the country code, for example +447700900123.']], self::errors($this->execute($test, ['to' => 'call me'])));
        $audit = Services::get($this->site->container(), AuditLog::class)->find(new AuditFilter(), 20);
        $summaries = implode("\n", array_map(static fn ($entry): string => $entry->summary, $audit));
        self::assertStringContainsString('Sent a test text message to +44•••••••456 with twilio.', $summaries);
        self::assertStringContainsString('Tried to send a test text message to +44•••••••456 with twilio: unsupported country.', $summaries);
        self::assertStringNotContainsString('7400123456', serialize($audit));

        self::assertSame([0, ['Text messages are off for "demo".']], self::lines($this->execute($this->disable(), [])));
        self::assertSame([0, ['Text messages were already off for "demo".']], self::lines($this->execute($this->disable(), [])));
    }

    public function testRemovesTheProvider(): void
    {
        $remove = $this->command(RemoveSmsCommand::class);
        self::assertSame(['site:sms:remove', 1], [$remove->name(), count($remove->parameters())]);
        self::assertSame([0, ['The site "demo" has no text message provider of its own.']], self::lines($this->execute($remove, [])));
        $this->execute($this->set($this->keyFile(self::KEY)), ['provider' => 'twilio', 'account' => self::ACCOUNT, 'sender' => 'SightPress'], flags: ['key-stdin']);
        $this->execute($this->enable(), []);
        $this->evict();
        self::assertSame([0, ['The text message provider of "demo" is removed.', 'Text messages are still on but can\'t be sent: set a provider with site:sms:set, or turn them off with site:sms:disable demo']], self::lines($this->execute($remove, [])));

        $this->site->remove();
        $this->boot(['sms' => ['provider' => 'twilio', 'account' => self::ACCOUNT, 'sender' => 'SightPress']]);
        $this->execute($this->set(), ['provider' => 'vonage']);
        $remove = $this->command(RemoveSmsCommand::class);
        self::assertSame([0, ['The text message provider of "demo" is removed.']], self::lines($this->execute($remove, [])));

        $this->site->remove();
        $this->site = new KernelSite('sms_commands', tenant: ['sms' => ['provider' => 'twilio', 'account' => self::ACCOUNT, 'sender' => 'SightPress']], variables: ['SMS_API_KEY' => self::KEY]);
        $this->execute($this->set(), ['provider' => 'vonage']);
        $remove = $this->command(RemoveSmsCommand::class);
        self::assertSame([0, ['The text message provider of "demo" is removed.', 'It uses your organisation\'s provider now.']], self::lines($this->execute($remove, [])));
    }

    private function evict(): void
    {
        Services::get($this->site->kernel->container(), SiteContainerFactory::class)->evict('demo');
    }

    private function keyFile(string $content): KeyInput
    {
        return new KeyInput($this->directory->write('key-' . bin2hex(random_bytes(4)), $content));
    }

    /**
     * @template T of SiteSmsCommand
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function command(string $class): SiteSmsCommand
    {
        $tenant = $this->site->kernel->container();

        return new $class(Services::get($tenant, SiteRepository::class), Services::get($tenant, SiteContainerFactory::class));
    }

    private function set(?KeyInput $keys = null): SetSmsCommand
    {
        $tenant = $this->site->kernel->container();

        return new SetSmsCommand(Services::get($tenant, SiteRepository::class), Services::get($tenant, SiteContainerFactory::class), $keys ?? new KeyInput('/dev/null'));
    }

    private function enable(): EnableSmsCommand
    {
        $tenant = $this->site->kernel->container();

        return new EnableSmsCommand(Services::get($tenant, SiteRepository::class), Services::get($tenant, SiteContainerFactory::class), Services::get($tenant, SiteModules::class));
    }

    private function disable(): DisableSmsCommand
    {
        $tenant = $this->site->kernel->container();

        return new DisableSmsCommand(Services::get($tenant, SiteRepository::class), Services::get($tenant, SiteContainerFactory::class), Services::get($tenant, SiteModules::class));
    }

    /**
     * @param array<string, string> $options
     * @param list<string>          $flags
     *
     * @return array{int, RecordingOutput}
     */
    private function execute(Command $command, array $options, string $slug = 'demo', array $flags = []): array
    {
        $output = new RecordingOutput();

        return [$command->run(new ArrayCommandInput(['slug' => $slug], $options, $flags), $output), $output];
    }

    /**
     * @param array{int, RecordingOutput} $result
     *
     * @return array{int, list<string>}
     */
    private static function lines(array $result): array
    {
        return [$result[0], $result[1]->lines];
    }

    /**
     * @param array{int, RecordingOutput} $result
     *
     * @return array{int, list<string>}
     */
    private static function errors(array $result): array
    {
        return [$result[0], $result[1]->errors];
    }
}
