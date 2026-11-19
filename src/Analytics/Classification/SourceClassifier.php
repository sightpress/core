<?php

declare(strict_types=1);

namespace SightPress\Core\Analytics\Classification;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Where a visit came from, as one short key (ADR-0012): `direct` (no referrer, or the site itself), `search:<name>`
 * and `ai:<name>` (the engines in `resources/analytics/search-engines.php`), `site:<host>` (any other site, host only,
 * without `www.`) or `campaign:<label>` (from `utm_source` or `ref` in the address, which come first). Never the
 * full referrer address.
 */
final class SourceClassifier
{
    public const string DIRECT = 'direct';

    /** Longest label kept from `utm_source` / `ref`, in characters. */
    public const int LABEL_LENGTH = 50;

    private const string LIST = __DIR__ . '/../../../resources/analytics/search-engines.php';

    /** @var array<string, array<string, list<string>>>|null */
    private ?array $engines;

    /**
     * @param array<string, array<string, list<string>>>|null $engines group (`ai`, `search`) => name => hosts; null
     *                                                                 reads the resource list
     */
    public function __construct(?array $engines = null)
    {
        $this->engines = $engines;
    }

    /**
     * @param list<string> $ownHosts the site's own domains
     */
    public function classify(ServerRequestInterface $request, array $ownHosts): string
    {
        $query = $request->getQueryParams();
        foreach (['utm_source', 'ref'] as $name) {
            $label = is_string($query[$name] ?? null) ? self::label($query[$name]) : '';
            if ($label !== '') {
                return 'campaign:' . $label;
            }
        }
        $host = parse_url(trim($request->getHeaderLine('Referer')), PHP_URL_HOST);
        if (!is_string($host) || preg_match('/^[a-z0-9.-]{1,253}$/i', $host) !== 1) {
            return self::DIRECT;
        }
        $host = strtolower(rtrim($host, '.'));
        $bare = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
        foreach ($ownHosts as $own) {
            $own = strtolower($own);
            if ($host === $own || $bare === (str_starts_with($own, 'www.') ? substr($own, 4) : $own)) {
                return self::DIRECT;
            }
        }
        foreach (['ai', 'search'] as $group) {
            foreach ($this->engines()[$group] ?? [] as $name => $patterns) {
                foreach ($patterns as $pattern) {
                    if (self::matches($host, $pattern)) {
                        return $group . ':' . $name;
                    }
                }
            }
        }

        return 'site:' . $bare;
    }

    /**
     * A campaign label made safe to keep and show: lower case, letters, digits, spaces, `.`, `_` and `-` only, at
     * most {@see LABEL_LENGTH} characters.
     */
    public static function label(string $value): string
    {
        $value = mb_strtolower((string) preg_replace('/[^\p{L}\p{N} ._-]+/u', ' ', $value));

        return trim(mb_substr(trim((string) preg_replace('/\s+/u', ' ', $value)), 0, self::LABEL_LENGTH));
    }

    private static function matches(string $host, string $pattern): bool
    {
        $regex = str_ends_with($pattern, '.*')
            ? '/(^|\.)' . preg_quote(substr($pattern, 0, -2), '/') . '\.[a-z]{2,3}(\.[a-z]{2})?$/'
            : '/(^|\.)' . preg_quote($pattern, '/') . '$/';

        return preg_match($regex, $host) === 1;
    }

    /**
     * @return array<string, array<string, list<string>>>
     */
    private function engines(): array
    {
        if ($this->engines === null) {
            /** @var array<string, array<string, list<string>>> $engines */
            $engines = require self::LIST;
            $this->engines = $engines;
        }

        return $this->engines;
    }
}
