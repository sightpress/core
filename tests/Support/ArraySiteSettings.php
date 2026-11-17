<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support;

use SightPress\Core\Site\Contract\SiteSettings;

/**
 * Site settings kept in memory, for unit tests that only need a few values.
 */
final class ArraySiteSettings implements SiteSettings
{
    /**
     * @param array<string, string> $values
     */
    public function __construct(private array $values = [])
    {
    }

    public function get(string $key): ?string
    {
        return $this->values[$key] ?? null;
    }

    public function set(string $key, string $value): void
    {
        $this->values[$key] = $value;
    }
}
