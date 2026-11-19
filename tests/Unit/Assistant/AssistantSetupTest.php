<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Assistant;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Template\Message;
use SightPress\Core\Assistant\AssistantConfiguration;
use SightPress\Core\Assistant\AssistantSetup;
use SightPress\Core\Tests\Support\Assistant\AssistantFixture;
use SightPress\Core\Tests\Support\Assistant\FakeProvider;
use SightPress\Core\Tests\Support\Assistant\FakeTask;

#[CoversClass(AssistantSetup::class)]
#[CoversClass(AssistantConfiguration::class)]
#[UsesNamespace('SightPress\Core')]
final class AssistantSetupTest extends TestCase
{
    public function testNothingIsChosenAtFirst(): void
    {
        $configuration = new AssistantFixture([new FakeProvider()])->setup->configuration();

        self::assertFalse($configuration->isReady());
        self::assertSame(['No AI service is chosen yet.', null, null, 0, 'tenant'], [$configuration->problem?->text, $configuration->provider, $configuration->connection, $configuration->budget, $configuration->source]);
    }

    public function testTheSitesOwnChoiceWins(): void
    {
        $fixture = new AssistantFixture([new FakeProvider()], tenant: ['provider' => 'fake', 'model' => 'fake-small', 'monthly_budget' => 1000], environment: ['ASSISTANT_API_KEY' => 'sk-tenant-0000']);
        $fixture->settings->connect('fake', 'fake-vision', null, 'sk-site-1111', 1);
        $fixture->settings->limit(50, ['admin'], 1);

        $configuration = $fixture->setup->configuration();

        self::assertTrue($configuration->isReady());
        self::assertTrue($fixture->setup->sitesMayChoose());
        self::assertSame(['fake', 'fake-vision', 'sk-site-1111', null, 50, 'site'], [$configuration->provider?->id(), $configuration->model, $configuration->connection?->apiKey, $configuration->connection?->baseUrl, $configuration->budget, $configuration->source]);
    }

    public function testSitesInheritTheTenantDefaultForWhatTheyLeaveEmpty(): void
    {
        $fixture = new AssistantFixture([new FakeProvider()], tenant: ['provider' => 'fake', 'model' => 'fake-small', 'monthly_budget' => 1000], environment: ['ASSISTANT_API_KEY' => 'sk-tenant-0000']);

        $configuration = $fixture->setup->configuration();
        self::assertSame(['fake', 'fake-small', 'sk-tenant-0000', 1000, 'tenant'], [$configuration->provider?->id(), $configuration->model, $configuration->connection?->apiKey, $configuration->budget, $configuration->source]);

        $fixture->settings->connect('fake', 'fake-vision', null, null, 1);
        $configuration = $fixture->setup->configuration();
        self::assertSame(['fake-vision', 'sk-tenant-0000', 'site'], [$configuration->model, $configuration->connection?->apiKey, $configuration->source], 'same provider: the tenant key is inherited');
    }

    public function testAnotherProviderNeedsItsOwnKeyAndModel(): void
    {
        $fixture = new AssistantFixture([new FakeProvider()], [new FakeTask()], tenant: ['provider' => 'other', 'model' => 'm', 'base_url' => 'https://x.example'], environment: ['ASSISTANT_API_KEY' => 'sk-tenant-0000']);

        self::assertSame('The chosen AI service ({provider}) isn’t available any more. Choose another one.', self::problem($fixture)->text);
        $fixture->siteSettings->set('assistant.provider', 'fake');
        self::assertSame('No model is chosen yet.', self::problem($fixture)->text);
        $fixture->siteSettings->set('assistant.model', 'fake-small');
        $problem = self::problem($fixture);
        self::assertSame(['There is no API key for {provider} yet.', ['provider' => 'Fake AI']], [$problem->text, $problem->parameters]);
    }

    public function testACustomServiceNeedsAValidAddress(): void
    {
        $fixture = new AssistantFixture([new FakeProvider(needsBaseUrl: true)]);
        $fixture->settings->connect('fake', 'local', null, 'sk-site-1111', 1);
        self::assertSame('{provider} needs the address of the service.', self::problem($fixture)->text);

        $fixture->siteSettings->set('assistant.base_url', 'not a url');
        $configuration = $fixture->setup->configuration();
        self::assertSame(['The address of the AI service isn’t valid. Enter it again.', null], [$configuration->problem?->text, $configuration->connection]);
        self::assertFalse($configuration->isReady());

        $fixture->siteSettings->set('assistant.base_url', 'https://ai.example.org/v1');
        self::assertSame('https://ai.example.org/v1', $fixture->setup->configuration()->connection?->baseUrl);
    }

    public function testWhenTheTenantChoosesSitesSettingsAreIgnored(): void
    {
        $fixture = new AssistantFixture([new FakeProvider()], tenant: ['provider' => 'fake', 'model' => 'fake-small', 'site_keys' => false], environment: ['ASSISTANT_API_KEY' => 'sk-tenant-0000']);
        $fixture->settings->connect('fake', 'fake-vision', null, 'sk-site-1111', 1);

        $configuration = $fixture->setup->configuration();

        self::assertFalse($fixture->setup->sitesMayChoose());
        self::assertSame(['fake-small', 'sk-tenant-0000', 'tenant'], [$configuration->model, $configuration->connection?->apiKey, $configuration->source]);
    }

    private static function problem(AssistantFixture $fixture): Message
    {
        $problem = $fixture->setup->configuration()->problem;
        self::assertNotNull($problem);

        return $problem;
    }
}
