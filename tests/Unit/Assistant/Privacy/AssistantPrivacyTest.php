<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Assistant\Privacy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Privacy\Row;
use SightPress\Core\Assistant\Privacy\AssistantProcessors;
use SightPress\Core\Assistant\Privacy\AssistantUsageData;
use SightPress\Core\Assistant\Provider\AnthropicProvider;
use SightPress\Core\Assistant\Provider\MistralProvider;
use SightPress\Core\Assistant\Provider\OpenAiCompatibleProvider;
use SightPress\Core\Assistant\Provider\OpenAiProvider;
use SightPress\Core\Assistant\Provider\ProviderModels;
use SightPress\Core\Assistant\Http\AssistantHttp;
use SightPress\Core\Assistant\Http\EndpointGuard;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Tests\Support\Assistant\AssistantFixture;
use SightPress\Core\Tests\Support\Assistant\FakeProvider;
use SightPress\Core\Tests\Support\Privacy\SiteTables;
use SightPress\Module\Privacy\ErasureRule;
use SightPress\Module\Privacy\UserRef;
use Symfony\Component\HttpClient\MockHttpClient;

#[CoversClass(AssistantProcessors::class)]
#[CoversClass(AssistantUsageData::class)]
#[UsesNamespace('SightPress\Core')]
final class AssistantPrivacyTest extends TestCase
{
    public function testExportsTheUsageAndKeepsItOnTheTombstone(): void
    {
        $connection = SiteTables::connection();
        foreach ([[1, 7], [2, 7], [3, 8]] as [$id, $user]) {
            $connection->insert('assistant_usage', ['id' => $id, 'used_at' => '2026-11-0' . $id . ' 10:00:00', 'month' => '2026-11', 'user_id' => $user, 'task' => 'summary', 'provider' => 'anthropic', 'model' => 'claude', 'input_tokens' => 10, 'output_tokens' => 5, 'outcome' => 'ok']);
        }
        $data = new AssistantUsageData($connection);
        $ada = new UserRef(7, 'ada@example.org');

        self::assertSame(ErasureRule::Pseudonymise, $data->describe()->erasure);
        self::assertSame(['used_at' => '2026-11-01T10:00:00Z', 'task' => 'summary', 'service' => 'anthropic', 'model' => 'claude', 'input_tokens' => 10, 'output_tokens' => 5, 'outcome' => 'ok'], iterator_to_array($data->export($ada), false)[0]);
        self::assertCount(2, iterator_to_array($data->export($ada), false));
        self::assertSame(2, $data->erase($ada)->pseudonymised);
        self::assertSame(3, Row::int($connection->fetchOne('SELECT COUNT(*) FROM assistant_usage')));
    }

    public function testDeclaresTheChosenServiceOnlyWhileItIsSetUp(): void
    {
        self::assertSame([], [...self::processors([])->processors()]);
        self::assertSame([], [...self::processors([])->outboundHosts()]);

        foreach (['anthropic' => ['assistant.anthropic', 'api.anthropic.com'], 'openai' => ['assistant.openai', 'api.openai.com'], 'mistral' => ['assistant.mistral', 'api.mistral.ai']] as $provider => [$id, $host]) {
            $processors = [...self::processors(['provider' => $provider, 'model' => 'm'])->processors()];
            self::assertSame($id, $processors[0]->id);
            self::assertSame([$host], $processors[0]->hosts);
            self::assertSame([], [...self::processors(['provider' => $provider, 'model' => 'm'])->outboundHosts()]);
        }

        $compatible = self::processors(['provider' => 'openai_compatible', 'model' => 'm', 'base_url' => 'https://ai.example.org/v1']);
        $processors = [...$compatible->processors()];
        self::assertSame(['assistant.openai_compatible', 'ai.example.org', ['ZZ'], ['ai.example.org']], [$processors[0]->id, $processors[0]->name, $processors[0]->countries, $processors[0]->hosts]);
        self::assertSame(['ai.example.org'], [...$compatible->outboundHosts()]);

        $private = self::processors(['provider' => 'openai_compatible', 'model' => 'm', 'base_url' => 'http://ollama:11434/v1', 'allow_private_hosts' => true], ['APP_ENV' => 'dev']);
        self::assertSame([], [...$private->processors()], 'a server on the operator\'s network is not a third party');
        self::assertSame(['ollama'], [...$private->outboundHosts()]);

        self::assertSame([], [...self::processors(['provider' => 'fake', 'model' => 'm'])->processors()], 'a module\'s service is declared by its module');
    }

    /**
     * @param array<string, mixed>  $tenant
     * @param array<string, string> $environment
     */
    private static function processors(array $tenant, array $environment = []): AssistantProcessors
    {
        $http = new AssistantHttp(new MockHttpClient(), new EndpointGuard(TenantConfig::fromArray([], '/tmp/project'), new \SightPress\Core\Assistant\Http\SystemHostResolver()));
        $models = new ProviderModels();
        $fixture = new AssistantFixture(
            [new AnthropicProvider($http, $models), new OpenAiProvider($http, $models), new MistralProvider($http, $models), new OpenAiCompatibleProvider($http, $models), new FakeProvider()],
            tenant: $tenant,
            environment: ['ASSISTANT_API_KEY' => 'key'] + $environment,
        );

        return new AssistantProcessors($fixture->setup);
    }
}
