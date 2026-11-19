<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant\Provider;

use SightPress\Core\Assistant\Http\AssistantHttp;
use SightPress\Module\Assistant\AssistantProvider;
use SightPress\Module\Assistant\ProviderCapabilities;

/**
 * What core's providers share: their models come from the maintained list ({@see ProviderModels}) and they talk
 * through {@see AssistantHttp}. They don't stream.
 */
abstract readonly class ListedModelsProvider implements AssistantProvider
{
    public function __construct(
        protected AssistantHttp $http,
        protected ProviderModels $models,
    ) {
    }

    public function models(): array
    {
        return $this->models->names($this->id());
    }

    public function needsBaseUrl(): bool
    {
        return false;
    }

    public function capabilities(string $model): ProviderCapabilities
    {
        return new ProviderCapabilities(vision: $this->models->vision($this->id(), $model));
    }

    /**
     * A token count from a provider's answer; anything that isn't a positive whole number counts as 0.
     *
     * @return int<0, max>
     */
    protected static function tokens(mixed $value): int
    {
        return is_int($value) && $value > 0 ? $value : 0;
    }
}
