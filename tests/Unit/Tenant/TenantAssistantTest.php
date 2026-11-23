<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Tenant;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Tenant\TenantAssistant;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Tenant\TenantConfigException;

#[CoversClass(TenantAssistant::class)]
#[UsesNamespace('SightPress\Core\Tenant')]
#[UsesNamespace('SightPress\Core\Http\Upload')]
#[UsesClass(\SightPress\Core\Media\MediaLimits::class)]
final class TenantAssistantTest extends TestCase
{
    public function testHasNoDefaultAndLetsSitesChooseUnlessConfigured(): void
    {
        $assistant = TenantConfig::fromArray([], '/tmp/project')->assistant;

        self::assertEquals(new TenantAssistant(), $assistant);
        self::assertFalse($assistant->hasDefault());
        self::assertTrue($assistant->siteKeys);
        self::assertFalse($assistant->allowPrivateHosts);
        self::assertSame(0, $assistant->monthlyBudget);
    }

    public function testReadsTheTenantDefaultWithItsKeyFromTheEnvironment(): void
    {
        $assistant = TenantConfig::fromArray(['assistant' => [
            'provider' => ' mistral ',
            'model' => 'mistral-small-latest',
            'base_url' => '',
            'site_keys' => false,
            'allow_private_hosts' => true,
            'monthly_budget' => 500000,
        ]], '/tmp/project', ['ASSISTANT_API_KEY' => ' sk-tenant-123456 '])->assistant;

        self::assertSame(['mistral', 'mistral-small-latest', null, 'sk-tenant-123456', false, true, 500000], [
            $assistant->provider, $assistant->model, $assistant->baseUrl, $assistant->apiKey, $assistant->siteKeys, $assistant->allowPrivateHosts, $assistant->monthlyBudget,
        ]);
        self::assertTrue($assistant->hasDefault());
        self::assertNull(TenantAssistant::fromArray([], ['ASSISTANT_API_KEY' => '  '])->apiKey);
        self::assertStringNotContainsString('sk-tenant', print_r(new \Exception('x'), true));
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function invalid(): iterable
    {
        yield 'budget text' => [['monthly_budget' => '100'], 'assistant.monthly_budget'];
        yield 'negative budget' => [['monthly_budget' => -1], 'assistant.monthly_budget'];
        yield 'provider list' => [['provider' => ['anthropic']], 'assistant.provider'];
        yield 'blank model' => [['model' => '  '], 'assistant.model'];
        yield 'site keys text' => [['site_keys' => 'no'], 'assistant.site_keys'];
        yield 'private hosts number' => [['allow_private_hosts' => 1], 'assistant.allow_private_hosts'];
    }

    /**
     * @param array<mixed> $section
     */
    #[DataProvider('invalid')]
    public function testRefusesSettingsItCannotUse(array $section, string $setting): void
    {
        $this->expectException(TenantConfigException::class);
        $this->expectExceptionMessage($setting);

        TenantAssistant::fromArray($section);
    }

    public function testTheSectionMustBeAnArray(): void
    {
        $this->expectException(TenantConfigException::class);
        $this->expectExceptionMessage('Tenant setting "assistant" must be an array.');

        TenantConfig::fromArray(['assistant' => 'anthropic'], '/tmp/project');
    }
}
