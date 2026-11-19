<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Assistant;

use SightPress\Core\Assistant\Http\HostResolver;

/**
 * A host resolver for tests that knows only the names it is given.
 */
final readonly class FixedResolver implements HostResolver
{
    /**
     * @param array<string, list<string>> $hosts
     */
    public function __construct(private array $hosts = [
        'api.anthropic.com' => ['160.79.104.10'],
        'api.openai.com' => ['162.159.140.245'],
        'api.mistral.ai' => ['51.159.0.1'],
        'ai.example.org' => ['93.184.215.14'],
    ])
    {
    }

    public function addresses(string $host): array
    {
        return $this->hosts[$host] ?? [];
    }
}
