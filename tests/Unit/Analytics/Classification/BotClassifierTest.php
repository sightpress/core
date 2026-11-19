<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Analytics\Classification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Analytics\Classification\Bot;
use SightPress\Core\Analytics\Classification\BotClassifier;

#[CoversClass(BotClassifier::class)]
#[CoversClass(Bot::class)]
final class BotClassifierTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?string, ?string}>
     */
    public static function userAgents(): iterable
    {
        yield 'Firefox' => ['Mozilla/5.0 (X11; Linux x86_64; rv:140.0) Gecko/20100101 Firefox/140.0', null, null];
        yield 'Safari on an iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1', null, null];
        yield 'a phone called Cubot' => ['Mozilla/5.0 (Linux; Android 13; CUBOT_X30) AppleWebKit/537.36 Mobile Safari/537.36', null, null];
        yield 'GPTBot trains AI' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)', 'GPTBot', Bot::AI_TRAINING];
        yield 'ClaudeBot trains AI' => ['Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)', 'ClaudeBot', Bot::AI_TRAINING];
        yield 'Claude-User answers a person' => ['Mozilla/5.0 (compatible; Claude-User/1.0; +Claude-User@anthropic.com)', 'Claude-User', Bot::AI_SEARCH];
        yield 'OAI-SearchBot' => ['Mozilla/5.0 (compatible; OAI-SearchBot/1.0; +https://openai.com/searchbot)', 'OAI-SearchBot', Bot::AI_SEARCH];
        yield 'PerplexityBot' => ['Mozilla/5.0 (compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)', 'PerplexityBot', Bot::AI_SEARCH];
        yield 'Googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', 'Googlebot', Bot::SEARCH];
        yield 'bingbot, in any case' => ['Mozilla/5.0 (compatible; BINGBOT/2.0; +http://www.bing.com/bingbot.htm)', 'bingbot', Bot::SEARCH];
        yield 'a link preview' => ['facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)', 'facebookexternalhit', Bot::OTHER];
        yield 'curl' => ['curl/8.5.0', 'curl', Bot::OTHER];
        yield 'an unnamed crawler' => ['ExampleCrawler/3.1 (+https://example.org/crawler)', BotClassifier::UNNAMED, Bot::OTHER];
        yield 'an unnamed bot' => ['acme-bot/1.0', BotClassifier::UNNAMED, Bot::OTHER];
        yield 'nothing at all' => ['  ', BotClassifier::NO_USER_AGENT, Bot::OTHER];
    }

    #[DataProvider('userAgents')]
    public function testTellsBotsFromPeople(string $userAgent, ?string $name, ?string $kind): void
    {
        $bot = new BotClassifier()->classify($userAgent);

        self::assertSame([$name, $kind], [$bot?->name, $bot?->kind]);
    }

    public function testSharesTheAiCrawlersWithRobotsTxt(): void
    {
        /** @var array{training: list<string>, search: list<string>} $crawlers */
        $crawlers = require dirname(__DIR__, 4) . '/resources/seo/ai-crawlers.php';
        $classifier = new BotClassifier();

        foreach (['training' => Bot::AI_TRAINING, 'search' => Bot::AI_SEARCH] as $list => $kind) {
            foreach ($crawlers[$list] as $name) {
                self::assertSame([$name, $kind], [$classifier->classify('Mozilla/5.0 (compatible; ' . $name . '/1.0)')?->name, $classifier->classify($name)?->kind], $name);
            }
        }
    }

    public function testTheFirstNameInTheListWins(): void
    {
        $classifier = new BotClassifier(['first' => ['Robo'], 'second' => ['RoboCop', 'Robo']]);

        self::assertSame(['Robo', 'first'], [$classifier->classify('RoboCop/1.0')?->name, $classifier->classify('RoboCop/1.0')?->kind]);
    }
}
