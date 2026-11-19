<?php

declare(strict_types=1);

namespace SightPress\Core\Analytics\Classification;

/**
 * Tells bots from people by their user agent, with the list in `resources/analytics/bots.php` (which shares the AI
 * crawlers with robots.txt). Only the name of a bot is kept, never the user agent.
 */
final class BotClassifier
{
    public const string UNNAMED = 'Other bot';
    public const string NO_USER_AGENT = 'No user agent';

    private const string LIST = __DIR__ . '/../../../resources/analytics/bots.php';

    /** @var array<string, string>|null name => kind, in matching order */
    private ?array $names = null;

    /**
     * @param array<string, list<string>>|null $kinds kind => names; null reads the resource list
     */
    public function __construct(private readonly ?array $kinds = null)
    {
    }

    /**
     * The bot the user agent belongs to, or null for a person's browser.
     */
    public function classify(string $userAgent): ?Bot
    {
        $userAgent = trim($userAgent);
        if ($userAgent === '') {
            return new Bot(self::NO_USER_AGENT, Bot::OTHER);
        }
        $lower = strtolower($userAgent);
        foreach ($this->names() as $name => $kind) {
            if (str_contains($lower, strtolower($name))) {
                return new Bot($name, $kind);
            }
        }

        return preg_match('/bot\b|crawl|spider|slurp|fetch/i', $userAgent) === 1 ? new Bot(self::UNNAMED, Bot::OTHER) : null;
    }

    /**
     * @return array<string, string>
     */
    private function names(): array
    {
        if ($this->names === null) {
            /** @var array<string, list<string>> $kinds */
            $kinds = $this->kinds ?? require self::LIST;
            $this->names = [];
            foreach ($kinds as $kind => $names) {
                foreach ($names as $name) {
                    $this->names[$name] ??= $kind;
                }
            }
        }

        return $this->names;
    }
}
