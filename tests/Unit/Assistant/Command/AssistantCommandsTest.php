<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Assistant\Command;

use DI\Container;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Assistant\AssistantSettings;
use SightPress\Core\Assistant\Command\AskAssistantCommand;
use SightPress\Core\Assistant\Command\AssistantUsageCommand;
use SightPress\Core\Assistant\Command\DisableAssistantCommand;
use SightPress\Core\Cli\KeyInput;
use SightPress\Core\Assistant\Command\SetAssistantCommand;
use SightPress\Core\Assistant\Command\SiteAssistantCommand;
use SightPress\Core\Assistant\Command\TestAssistantCommand;
use SightPress\Core\Audit\AuditFilter;
use SightPress\Core\Audit\AuditLog;
use SightPress\Core\Infrastructure\Container\Services;
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

#[CoversClass(SiteAssistantCommand::class)]
#[CoversClass(SetAssistantCommand::class)]
#[CoversClass(DisableAssistantCommand::class)]
#[CoversClass(TestAssistantCommand::class)]
#[CoversClass(AssistantUsageCommand::class)]
#[CoversClass(AskAssistantCommand::class)]
#[CoversClass(KeyInput::class)]
#[UsesNamespace('SightPress\Core')]
final class AssistantCommandsTest extends TestCase
{
    private const string KEY = 'sk-canary-5f2b9c1d7e4a';

    private const string SERVICE = 'https://8.8.8.8/v1';

    private KernelSite $site;

    private TemporaryDirectory $directory;

    /** @var list<array{url: string, body: string, headers: list<string>}> */
    private array $sent = [];

    /** @var list<MockResponse> */
    private array $answers = [];

    protected function setUp(): void
    {
        $this->site = new KernelSite('assistant_commands');
        $this->directory = new TemporaryDirectory();
        $tenant = $this->site->kernel->container();
        self::assertInstanceOf(Container::class, $tenant);
        $tenant->set(HttpClientInterface::class, new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $headers = is_array($options['headers'] ?? null) ? array_values(array_filter($options['headers'], is_string(...))) : [];
            $this->sent[] = ['url' => $url, 'body' => is_string($options['body'] ?? null) ? $options['body'] : '', 'headers' => $headers];

            return array_shift($this->answers) ?? new MockResponse('{}', ['http_code' => 500]);
        }));
    }

    protected function tearDown(): void
    {
        $this->site->remove();
        $this->directory->remove();
    }

    public function testEveryCommandNeedsASiteWithTheAssistantOn(): void
    {
        foreach ([TestAssistantCommand::class, AssistantUsageCommand::class] as $class) {
            $command = $this->command($class);
            self::assertSame([1, ['There is no site "shop". See the sites with site:list.']], self::errors($this->execute($command, [], 'shop')));
            self::assertSame([1, ['The AI assistant is off for the site "demo". Turn it on with: site:module:enable demo assistant']], self::errors($this->execute($command, [])));
        }
        self::assertSame([1, ['There is no site "shop". See the sites with site:list.']], self::errors($this->execute($this->disable(), [], 'shop')));
    }

    public function testSetsTheServiceAndReadsTheKeyFromStandardInput(): void
    {
        $this->turnOn();
        $set = $this->set($this->keyFile(self::KEY . "\nsecond line\n"));
        self::assertSame(['site:assistant:set', 5], [$set->name(), count($set->parameters())]);
        self::assertNotSame('', $set->description());

        [$code, $output] = $this->execute($set, ['provider' => 'openai_compatible', 'model' => 'llama-3', 'base-url' => self::SERVICE], flags: ['key-stdin']);

        self::assertSame(0, $code);
        self::assertSame([
            'The assistant of "demo" now uses Another OpenAI-compatible service with llama-3.',
            'API key: ••••7e4a',
            'Check it with: site:assistant:test demo',
        ], $output->lines);
        $settings = Services::get($this->site->container(), AssistantSettings::class);
        self::assertSame([self::KEY, 'openai_compatible', 'llama-3', self::SERVICE], [$settings->key(), $settings->provider(), $settings->model(), $settings->baseUrl()]);
        self::assertStringNotContainsString(self::KEY, implode("\n", $output->lines));
        $audit = Services::get($this->site->container(), AuditLog::class)->find(new AuditFilter(), 10);
        self::assertStringNotContainsString(self::KEY, serialize($audit));
        self::assertStringContainsString('AI service key', $audit[0]->summary);

        [, $output] = $this->execute($this->set(), ['provider' => 'openai_compatible', 'model' => 'llama-3', 'base-url' => self::SERVICE]);
        self::assertSame('Nothing changed for "demo".', $output->lines[0]);
        [, $output] = $this->execute($this->set(), ['provider' => 'anthropic', 'model' => 'claude-haiku-5-5']);
        self::assertSame(['The assistant of "demo" now uses Anthropic (Claude) with claude-haiku-5-5.', 'API key: ••••7e4a', 'Check it with: site:assistant:test demo'], $output->lines);

        $settings->removeKey(null);
        [, $output] = $this->execute($this->set(), ['provider' => 'anthropic', 'model' => 'claude-opus-5-5']);
        self::assertSame(['The assistant of "demo" now uses Anthropic (Claude) with claude-opus-5-5.', 'The site has no key of its own.', 'Not ready yet: There is no API key for Anthropic (Claude) yet.'], $output->lines);
    }

    public function testRefusesWhatItCannotSet(): void
    {
        $this->turnOn();
        $set = $this->set($this->keyFile(''));

        self::assertSame([2, ['There is no AI service "skynet". Choose one of: anthropic, openai, mistral, openai_compatible.']], self::errors($this->execute($set, ['provider' => 'skynet', 'model' => 'x'])));
        self::assertSame([2, ['Mistral AI has no model "gpt-5". Choose one of: mistral-large-latest, mistral-medium-latest, mistral-small-latest.']], self::errors($this->execute($set, ['provider' => 'mistral', 'model' => 'gpt-5'])));
        self::assertSame([2, ['Another OpenAI-compatible service needs --base-url, the address of the service.']], self::errors($this->execute($set, ['provider' => 'openai_compatible', 'model' => 'llama'])));
        self::assertSame([2, ['The assistant’s address must start with https://, so the key and your content are encrypted on the way.']], self::errors($this->execute($set, ['provider' => 'openai_compatible', 'model' => 'llama', 'base-url' => 'http://8.8.8.8/v1'])));
        self::assertSame(1, preg_match('/is on a private or local network/', self::errors($this->execute($set, ['provider' => 'openai_compatible', 'model' => 'llama', 'base-url' => 'https://127.0.0.1/v1']))[1][0]));
        self::assertSame([2, ['OpenAI (GPT) doesn\'t take a --base-url.']], self::errors($this->execute($set, ['provider' => 'openai', 'model' => 'gpt-5', 'base-url' => self::SERVICE])));
        self::assertSame([2, ['No key was read from standard input. Pipe it in, for example: printf %s "$KEY" | bin/sightpress site:assistant:set …']], self::errors($this->execute($set, ['provider' => 'openai', 'model' => 'gpt-5'], flags: ['key-stdin'])));
        self::assertSame('', new KeyInput($this->directory->path . '/missing')->read());
        self::assertNull(Services::get($this->site->container(), AssistantSettings::class)->provider());
    }

    public function testWhenTheTenantChoosesSitesCannotSetTheirOwn(): void
    {
        $this->site->remove();
        $this->site = new KernelSite('assistant_commands', tenant: ['assistant' => ['provider' => 'anthropic', 'model' => 'claude-haiku-5-5', 'site_keys' => false]], variables: ['ASSISTANT_API_KEY' => self::KEY]);
        $this->turnOn();

        self::assertSame([1, ['Your organisation chooses the AI service for every site (tenant setting assistant.site_keys is false).']], self::errors($this->execute($this->set(), ['provider' => 'openai', 'model' => 'gpt-5'])));
    }

    public function testTriesTheConnectionAndAsksQuestions(): void
    {
        $this->turnOn();
        self::assertSame([1, ['No AI service is chosen yet.']], self::errors($this->execute($this->command(AskAssistantCommand::class), [], arguments: ['question' => 'Hello?'])));
        $this->execute($this->set($this->keyFile(self::KEY)), ['provider' => 'openai_compatible', 'model' => 'llama-3', 'base-url' => self::SERVICE], flags: ['key-stdin']);
        $test = $this->command(TestAssistantCommand::class);
        self::assertSame(['site:assistant:test', 1], [$test->name(), count($test->parameters())]);
        self::assertNotSame('', $test->description());

        $this->answers = [self::answer('OK', 12, 1)];
        self::assertSame([0, ['The connection works: Another OpenAI-compatible service answered with llama-3 (13 tokens, the site\'s own key).']], self::lines($this->execute($test, [])));
        self::assertSame(self::SERVICE . '/chat/completions', $this->sent[0]['url']);
        self::assertContains('Authorization: Bearer ' . self::KEY, $this->sent[0]['headers']);

        $this->answers = [new MockResponse('{"error":{"code":"invalid_api_key","message":"Incorrect API key ' . self::KEY . '"}}', ['http_code' => 401])];
        [$code, $output] = $this->execute($test, []);
        self::assertSame([1, ['The connection doesn\'t work: The AI service didn’t accept the API key. An admin can replace it on the AI assistant screen.']], [$code, $output->errors]);
        self::assertStringNotContainsString(self::KEY, implode("\n", [...$output->lines, ...$output->errors]));
        self::assertStringNotContainsString(self::KEY, $this->site->project->log());

        $ask = $this->command(AskAssistantCommand::class);
        self::assertSame(['assistant:ask', 2], [$ask->name(), count($ask->parameters())]);
        self::assertNotSame('', $ask->description());
        $this->answers = [self::answer('Go to <b>Pages</b> and choose “New page”.', 50, 10, 'length')];
        self::assertSame([0, ['Go to Pages and choose “New page”.', '(The answer was cut short.)']], self::lines($this->execute($ask, [], arguments: ['question' => '  How do I add a page?  '])));
        self::assertStringContainsString('How do I add a page?', $this->sent[2]['body']);

        Services::get($this->site->container(), AssistantSettings::class)->limit(100, ['admin'], null);
        $this->answers = [self::answer('Fine.', 10, 5)];
        self::assertSame([0, ['Fine.', 'This site has used 88% of its AI assistant budget for this month.']], self::lines($this->execute($ask, [], arguments: ['question' => 'Again?'])));

        $usage = $this->command(AssistantUsageCommand::class);
        self::assertSame(['site:assistant:usage', 2], [$usage->name(), count($usage->parameters())]);
        self::assertNotSame('', $usage->description());
        [$code, $output] = $this->execute($usage, []);
        $month = gmdate('Y-m');
        self::assertSame([0, [
            sprintf('AI assistant use of "demo" in %s (UTC):', $month),
            '  ask: 2 uses, 60 tokens in, 15 out',
            '  connection_test: 2 uses (1 failed), 12 tokens in, 1 out',
            'Budget: 88 of 100 tokens used this month (88%). Nearly used up.',
        ]], [$code, $output->lines]);
    }

    public function testShowsUsageOfOtherMonthsAndBudgetStates(): void
    {
        $this->turnOn();
        $usage = $this->command(AssistantUsageCommand::class);

        self::assertSame([0, ['AI assistant use of "demo" in 2020-01 (UTC):', '  Not used.']], self::lines($this->execute($usage, ['month' => '2020-01'])));
        self::assertSame([2, ['"2020-13" isn\'t a month written as YYYY-MM.']], self::errors($this->execute($usage, ['month' => '2020-13'])));
        self::assertSame('Budget: no limit (0 tokens used this month).', $this->execute($usage, [])[1]->lines[2]);

        $settings = Services::get($this->site->container(), AssistantSettings::class);
        $settings->limit(10, ['admin'], null);
        Services::get($this->site->container(), \SightPress\Core\Assistant\AssistantUsageStore::class)->record(new \SightPress\Core\Assistant\AssistantUse(new \DateTimeImmutable(), null, 'ask', 'x', 'm', new \SightPress\Module\Assistant\TokenUsage(10, 0), 'ok'));
        self::assertSame('Budget: 10 of 10 tokens used this month (100%). The assistant refuses until next month.', $this->execute($usage, [])[1]->lines[2]);
        $settings->limit(1000, ['admin'], null);
        self::assertSame('Budget: 10 of 1000 tokens used this month (1%).', $this->execute($usage, [])[1]->lines[2]);
    }

    public function testDisablesTheAssistantAndCanForgetTheKey(): void
    {
        $this->turnOn();
        $disable = $this->disable();
        self::assertSame(['site:assistant:disable', 2], [$disable->name(), count($disable->parameters())]);
        self::assertNotSame('', $disable->description());
        Services::get($this->site->container(), AssistantSettings::class)->connect('openai', 'gpt-5', null, self::KEY, null);

        self::assertSame([0, ['The API key of "demo" is removed.', 'The AI assistant is off for "demo". Its settings are kept.']], self::lines($this->execute($disable, [], flags: ['remove-key'])));
        self::assertSame([0, ['"demo" has no API key of its own.', 'The AI assistant was already off for "demo".']], self::lines($this->execute($this->disable(), [], flags: ['remove-key'])));
        self::assertSame([0, ['The AI assistant was already off for "demo".']], self::lines($this->execute($this->disable(), [])));

        $this->site->remove();
        $this->site = new KernelSite('assistant_commands', tenant: ['modules' => ['locked' => ['assistant' => true]]]);
        self::assertSame(1, $this->execute($this->disable(), [])[0], 'locked by the organisation');
    }

    private function turnOn(): void
    {
        $tenant = $this->site->kernel->container();
        Services::get($tenant, SiteModules::class)->turnOn($this->site->site(), 'assistant', null);
        Services::get($tenant, SiteContainerFactory::class)->evict('demo');
    }

    private function keyFile(string $content): KeyInput
    {
        return new KeyInput($this->directory->write('key-' . bin2hex(random_bytes(4)), $content));
    }

    /**
     * @template T of SiteAssistantCommand
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function command(string $class): SiteAssistantCommand
    {
        $tenant = $this->site->kernel->container();

        return new $class(Services::get($tenant, SiteRepository::class), Services::get($tenant, SiteContainerFactory::class));
    }

    private function set(?KeyInput $keys = null): SetAssistantCommand
    {
        $tenant = $this->site->kernel->container();

        return new SetAssistantCommand(Services::get($tenant, SiteRepository::class), Services::get($tenant, SiteContainerFactory::class), $keys ?? new KeyInput('/dev/null'));
    }

    private function disable(): DisableAssistantCommand
    {
        $tenant = $this->site->kernel->container();

        return new DisableAssistantCommand(Services::get($tenant, SiteRepository::class), Services::get($tenant, SiteModules::class), Services::get($tenant, SiteContainerFactory::class));
    }

    /**
     * @param array<string, string> $options
     * @param list<string>          $flags
     * @param array<string, string> $arguments
     *
     * @return array{int, RecordingOutput}
     */
    private function execute(Command $command, array $options, string $slug = 'demo', array $flags = [], array $arguments = []): array
    {
        $output = new RecordingOutput();

        return [$command->run(new ArrayCommandInput(['slug' => $slug] + $arguments, $options, $flags), $output), $output];
    }

    private static function answer(string $text, int $in, int $out, string $finish = 'stop'): MockResponse
    {
        return new MockResponse((string) json_encode(['model' => 'llama-3', 'choices' => [['finish_reason' => $finish, 'message' => ['content' => $text]]], 'usage' => ['prompt_tokens' => $in, 'completion_tokens' => $out]]), ['http_code' => 200]);
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

    /**
     * @param array{int, RecordingOutput} $result
     *
     * @return array{int, list<string>}
     */
    private static function lines(array $result): array
    {
        return [$result[0], $result[1]->lines];
    }
}
