<?php

declare(strict_types=1);

namespace SightPress\Core\Seo;

use SightPress\Core\Site\Contract\SiteSettings;

/**
 * Whether AI crawlers may read the site (D11), the site setting `seo.ai_crawlers`:
 *
 * - `allow_all` (the default): every crawler may read public pages, like search engines;
 * - `search_only`: crawlers that train AI models are kept out; AI search and assistants that answer people's
 *   questions with a link back may still read the site;
 * - `block_all`: every AI crawler is kept out (search engines are not).
 *
 * The crawlers are listed in `resources/seo/ai-crawlers.php`. Only public, indexable pages are ever offered to
 * them (`llms.txt`, Markdown versions); robots.txt rules are a request that well-behaved crawlers follow.
 */
final readonly class AiCrawlerPolicy
{
    public const string SETTING = 'seo.ai_crawlers';

    public const string ALLOW_ALL = 'allow_all';

    public const string SEARCH_ONLY = 'search_only';

    public const string BLOCK_ALL = 'block_all';

    public const array CHOICES = [
        self::ALLOW_ALL => 'Allow all AI crawlers',
        self::SEARCH_ONLY => 'Allow AI search and assistants, block AI training',
        self::BLOCK_ALL => 'Block all AI crawlers',
    ];

    public function __construct(private SiteSettings $settings)
    {
    }

    public function current(): string
    {
        $value = $this->settings->get(self::SETTING);

        return $value !== null && isset(self::CHOICES[$value]) ? $value : self::ALLOW_ALL;
    }

    /**
     * The user agents robots.txt keeps out under the current policy.
     *
     * @return list<string>
     */
    public function blocked(): array
    {
        $crawlers = self::crawlers();

        return match ($this->current()) {
            self::SEARCH_ONLY => $crawlers['training'],
            self::BLOCK_ALL => [...$crawlers['training'], ...$crawlers['search']],
            default => [],
        };
    }

    /**
     * @return array{training: list<string>, search: list<string>}
     */
    public static function crawlers(): array
    {
        /** @var array{training: list<string>, search: list<string>} */
        return require dirname(__DIR__, 2) . '/resources/seo/ai-crawlers.php';
    }
}
