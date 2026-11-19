<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant\Provider;

use LogicException;

/**
 * The models offered for each of core's providers, from the maintained list in `resources/assistant/providers.php`
 * (D15): their names, labels and whether they can describe pictures. Read once per process.
 */
final class ProviderModels
{
    /** @var array<string, array<non-empty-string, array{0: string, 1: bool}>>|null */
    private ?array $models = null;

    public function __construct(private readonly string $path = __DIR__ . '/../../../resources/assistant/providers.php')
    {
    }

    /**
     * @return list<non-empty-string>
     */
    public function names(string $provider): array
    {
        return array_keys($this->all()[$provider] ?? []);
    }

    public function label(string $provider, string $model): string
    {
        return $this->all()[$provider][$model][0] ?? $model;
    }

    public function vision(string $provider, string $model): bool
    {
        return $this->all()[$provider][$model][1] ?? false;
    }

    /**
     * @return array<string, array<non-empty-string, array{0: string, 1: bool}>>
     */
    private function all(): array
    {
        if ($this->models !== null) {
            return $this->models;
        }
        $list = is_file($this->path) ? require $this->path : null;
        if (!is_array($list)) {
            throw new LogicException(sprintf('The assistant model list %s must return an array.', $this->path));
        }
        $models = [];
        foreach ($list as $provider => $entries) {
            if (!is_string($provider) || !is_array($entries)) {
                throw new LogicException(sprintf('The assistant model list %s must map provider ids to their models.', $this->path));
            }
            foreach ($entries as $model => $entry) {
                if (!is_string($model) || $model === '' || !is_array($entry) || !is_string($entry[0] ?? null) || !is_bool($entry[1] ?? null)) {
                    throw new LogicException(sprintf('Model %s of "%s" in %s must be written as [label, vision].', var_export($model, true), $provider, $this->path));
                }
                $models[$provider][$model] = [$entry[0], $entry[1]];
            }
        }

        return $this->models = $models;
    }
}
