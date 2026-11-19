<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Assistant;

use Closure;
use SightPress\Module\Assistant\AssistantConnection;
use SightPress\Module\Assistant\AssistantProvider;
use SightPress\Module\Assistant\AssistantRequest;
use SightPress\Module\Assistant\AssistantResponse;
use SightPress\Module\Assistant\ProviderCapabilities;
use SightPress\Module\Assistant\TokenUsage;

/**
 * An assistant provider for tests: answers what its closure returns (or throws), and keeps the requests.
 */
final class FakeProvider implements AssistantProvider
{
    /** @var list<AssistantRequest> */
    public array $requests = [];

    /** @var list<AssistantConnection> */
    public array $connections = [];

    /**
     * @param (Closure(AssistantRequest): AssistantResponse)|null $answer
     * @param list<non-empty-string>                              $models
     */
    public function __construct(
        private ?Closure $answer = null,
        private string $id = 'fake',
        private array $models = ['fake-small', 'fake-vision'],
        private bool $needsBaseUrl = false,
    ) {
    }

    public function id(): string
    {
        return $this->id === '' ? 'fake' : $this->id;
    }

    public function label(): string
    {
        return 'Fake AI';
    }

    public function description(): string
    {
        return 'A fake service for tests.';
    }

    public function models(): array
    {
        return $this->models;
    }

    public function needsBaseUrl(): bool
    {
        return $this->needsBaseUrl;
    }

    public function capabilities(string $model): ProviderCapabilities
    {
        return new ProviderCapabilities(vision: $model === 'fake-vision');
    }

    public function complete(AssistantRequest $request, AssistantConnection $connection): AssistantResponse
    {
        $this->requests[] = $request;
        $this->connections[] = $connection;

        return $this->answer === null ? new AssistantResponse('OK', $request->model, new TokenUsage(10, 2)) : ($this->answer)($request);
    }
}
