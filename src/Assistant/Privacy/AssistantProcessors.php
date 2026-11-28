<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant\Privacy;

use SightPress\Core\Assistant\AssistantSetup;
use SightPress\Core\Assistant\Provider\AnthropicProvider;
use SightPress\Core\Assistant\Provider\MistralProvider;
use SightPress\Core\Assistant\Provider\OpenAiCompatibleProvider;
use SightPress\Core\Assistant\Provider\OpenAiProvider;
use SightPress\Core\Infrastructure\Http\OutboundHostSource;
use SightPress\Module\Exception\InvalidValueException;
use SightPress\Module\Privacy\ProcessorDeclaration;
use SightPress\Module\Privacy\ProcessorSource;
use SightPress\Module\Text\Message;

/**
 * The AI service, while the site has one set up (ADR-0040, ADR-0075). For an OpenAI-compatible service the operator
 * chose, its host is declared too; a server on the operator's own network (which the tenant must allow) isn't a
 * third party, so it is only allowed as an outgoing address. Services that other modules add are declared by those
 * modules.
 */
final readonly class AssistantProcessors implements ProcessorSource, OutboundHostSource
{
    /** "Not known": the operator chose the service, so where it runs is for them to say. */
    public const string UNKNOWN_COUNTRY = 'ZZ';

    public function __construct(private AssistantSetup $setup)
    {
    }

    public function processors(): iterable
    {
        $configuration = $this->setup->configuration();
        if (!$configuration->isReady() || $configuration->provider === null) {
            return [];
        }
        $sent = new Message('The text, page content and pictures someone asks the assistant about, only when they ask for a suggestion. Not who asked.');

        return match ($configuration->provider->id()) {
            AnthropicProvider::ID => [new ProcessorDeclaration('assistant.anthropic', 'Anthropic PBC', $sent, ['US'], [self::host(AnthropicProvider::ENDPOINT)], 'https://www.anthropic.com/legal/privacy')],
            OpenAiProvider::ID => [new ProcessorDeclaration('assistant.openai', 'OpenAI', $sent, ['US'], [self::host(OpenAiProvider::BASE_URL)], 'https://openai.com/policies/privacy-policy/')],
            MistralProvider::ID => [new ProcessorDeclaration('assistant.mistral', 'Mistral AI', $sent, ['FR'], [self::host(MistralProvider::BASE_URL)], 'https://mistral.ai/terms/')],
            OpenAiCompatibleProvider::ID => $this->compatible($sent),
            default => [],
        };
    }

    public function outboundHosts(): iterable
    {
        $host = $this->compatibleHost();

        return $host === null ? [] : [$host];
    }

    /**
     * @return list<ProcessorDeclaration>
     */
    private function compatible(Message $sent): array
    {
        $host = $this->compatibleHost() ?? '';
        try {
            return [new ProcessorDeclaration('assistant.openai_compatible', $host, $sent, [self::UNKNOWN_COUNTRY], [$host])];
        } catch (InvalidValueException) {
            // A host such as "ollama" or an IP address: a server of the operator's, allowed by outboundHosts().
            return [];
        }
    }

    private function compatibleHost(): ?string
    {
        $configuration = $this->setup->configuration();
        if (!$configuration->isReady() || $configuration->provider?->id() !== OpenAiCompatibleProvider::ID) {
            return null;
        }
        $host = self::host($configuration->connection->baseUrl ?? '');

        return $host === '' ? null : $host;
    }

    private static function host(string $url): string
    {
        return strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
    }
}
