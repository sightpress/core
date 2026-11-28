<?php

declare(strict_types=1);

namespace SightPress\Core\Infrastructure\Http;

use Closure;

/**
 * The hosts the site may send requests to (GD-1): those the third parties it uses right now declare
 * ({@see \SightPress\Module\Privacy\ProcessorDeclaration::$hosts}) and the operator's own services (storage, a
 * private AI server). Read when a request is made, so turning a feature on or off changes it at once.
 *
 * A pattern is a host name (`api.example.org`) or `*.` and a domain (`*.example.org`), which matches every subdomain
 * but not the domain itself.
 */
final readonly class OutboundHosts
{
    /**
     * @param Closure(): iterable<string> $patterns
     */
    public function __construct(private Closure $patterns)
    {
    }

    /**
     * @param list<string> $patterns
     */
    public static function of(array $patterns): self
    {
        return new self(static fn (): array => $patterns);
    }

    public function allows(string $host): bool
    {
        $host = strtolower(rtrim($host, '.'));
        if ($host === '') {
            return false;
        }
        foreach (($this->patterns)() as $pattern) {
            $pattern = strtolower($pattern);
            if ($pattern === $host || (str_starts_with($pattern, '*.') && str_ends_with($host, substr($pattern, 1)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string> every pattern, sorted, each once
     */
    public function patterns(): array
    {
        $patterns = [];
        foreach (($this->patterns)() as $pattern) {
            $patterns[strtolower($pattern)] = true;
        }
        $patterns = array_map(strval(...), array_keys($patterns));
        sort($patterns);

        return $patterns;
    }
}
