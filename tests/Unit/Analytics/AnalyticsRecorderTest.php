<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Analytics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Analytics\AnalyticsRecorder;
use SightPress\Core\Analytics\AnalyticsStore;
use SightPress\Core\Http\Contract\AnsweredResource;
use SightPress\Core\Tests\Support\Analytics\AnalyticsFixture;

#[CoversClass(AnalyticsRecorder::class)]
#[UsesNamespace('SightPress\Core')]
final class AnalyticsRecorderTest extends TestCase
{
    public function testCountsAPageReadWithTheVisitorOfTheDaySourceLanguageAndDevice(): void
    {
        $fixture = new AnalyticsFixture();

        $fixture->view(4, 'pt-PT', ['Referer' => 'https://www.google.pt/search?q=private', 'Sec-CH-UA-Mobile' => '?1'], path: '/pt-pt/sobre?utm_medium=x');

        $hits = $fixture->rows(AnalyticsStore::HITS);
        self::assertCount(1, $hits);
        self::assertSame(
            ['2026-11-14', 'view', 'page', 4, 'pt-PT', null, 'search:Google', 'phone', null, null, null, null],
            [$hits[0]['day_on'], $hits[0]['kind'], $hits[0]['resource_kind'], (int) $hits[0]['page_id'], $hits[0]['locale'], $hits[0]['path'], $hits[0]['source'], $hits[0]['device'], $hits[0]['bot'], $hits[0]['bot_kind'], $hits[0]['search_query'], $hits[0]['result_count']],
        );
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $hits[0]['visitor_key']);
    }

    public function testTheSameVisitorHasTheSameKeyAllDayAndAnotherKeyTheNextDay(): void
    {
        $fixture = new AnalyticsFixture();

        $fixture->view(1, at: '2026-11-14 09:00 UTC');
        $fixture->view(2, at: '2026-11-14 23:30 UTC');
        $fixture->view(1, ip: '192.0.2.99', at: '2026-11-14 10:00 UTC');
        $fixture->view(1, at: '2026-11-15 09:00 UTC');

        $keys = array_column($fixture->rows(AnalyticsStore::HITS), 'visitor_key');
        self::assertSame($keys[0], $keys[1], 'same person, same day');
        self::assertNotSame($keys[0], $keys[2], 'another address');
        self::assertNotSame($keys[0], $keys[3], 'another day');
    }

    public function testDaysRunMidnightToMidnightInTheSitesTimeZoneAcrossAClockChange(): void
    {
        // Europe/London goes back from BST (UTC+1) to GMT at 01:00 UTC on 25 October 2026.
        $fixture = new AnalyticsFixture('Europe/London', '2026-10-25 12:00 UTC');
        foreach (['2026-10-24 22:59 UTC', '2026-10-24 23:00 UTC', '2026-10-25 23:59 UTC', '2026-10-26 00:00 UTC'] as $at) {
            $fixture->view(at: $at);
        }
        // Lisbon goes forward from WET (UTC) to WEST (UTC+1) at 01:00 UTC on 29 March 2026.
        $spring = new AnalyticsFixture('Europe/Lisbon', '2026-03-29 12:00 UTC');
        foreach (['2026-03-28 23:59 UTC', '2026-03-29 00:00 UTC', '2026-03-29 22:59 UTC', '2026-03-29 23:00 UTC'] as $at) {
            $spring->view(at: $at);
        }

        self::assertSame(['2026-10-24', '2026-10-25', '2026-10-25', '2026-10-26'], array_column($fixture->rows(AnalyticsStore::HITS), 'day_on'));
        self::assertSame(['2026-03-28', '2026-03-29', '2026-03-29', '2026-03-30'], array_column($spring->rows(AnalyticsStore::HITS), 'day_on'));
    }

    /**
     * @return iterable<string, array{string, array<string, string>, string, bool}>
     */
    public static function notCounted(): iterable
    {
        yield 'HEAD' => ['/', [], 'HEAD', false];
        yield 'POST' => ['/', [], 'POST', false];
        yield 'Global Privacy Control' => ['/', ['Sec-GPC' => '1'], 'GET', false];
        yield 'Do Not Track' => ['/', ['DNT' => '1'], 'GET', false];
        yield 'a prefetch' => ['/', ['Sec-Purpose' => 'prefetch'], 'GET', false];
        yield 'a prerender' => ['/', ['Sec-Purpose' => 'prefetch;prerender'], 'GET', false];
        yield 'an old prefetch' => ['/', ['Purpose' => 'prefetch'], 'GET', false];
        yield 'a preview' => ['/', ['X-Purpose' => 'preview'], 'GET', false];
        yield 'a signed-in user' => ['/', [], 'GET', true];
        yield 'the admin' => ['/admin', [], 'GET', false];
        yield 'an admin screen' => ['/admin/pages', [], 'GET', false];
        yield 'an asset' => ['/assets/default/site.css', [], 'GET', false];
        yield 'a media file' => ['/media/0123/photo.jpg', [], 'GET', false];
        yield 'a test address' => ['/_test/reset', [], 'GET', false];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('notCounted')]
    public function testNeverCounts(string $path, array $headers, string $method, bool $signedIn): void
    {
        $fixture = new AnalyticsFixture();
        $resource = new AnsweredResource();
        $resource->page(1, 'en-GB');
        $fixture->pending->add('opening hours', 'en-GB', 1);

        $fixture->answer(AnalyticsFixture::request($path, $headers, $method), resource: $resource, signedIn: $signedIn);
        $fixture->answer(AnalyticsFixture::request($path, $headers, $method), 404, signedIn: $signedIn);

        self::assertSame([], $fixture->rows(AnalyticsStore::HITS));
        self::assertSame([], $fixture->pending->take(), 'searches are dropped with the request');
        self::assertSame([], $fixture->rows(AnalyticsStore::SALTS));
    }

    public function testBotsAreCountedByNameAndWhatTheyReadNeverAsVisitors(): void
    {
        $fixture = new AnalyticsFixture();
        $llms = new AnsweredResource();
        $llms->file('llms');
        $page = new AnsweredResource();
        $page->page(3, 'en-GB', markdown: true);
        $gpt = ['User-Agent' => 'Mozilla/5.0 (compatible; GPTBot/1.2; +https://openai.com/gptbot)'];

        $fixture->answer(AnalyticsFixture::request('/llms.txt', $gpt), resource: $llms);
        $fixture->answer(AnalyticsFixture::request('/about.md', ['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1)']), resource: $page);
        $fixture->answer(AnalyticsFixture::request('/nothing', $gpt), 404);
        $fixture->answer(AnalyticsFixture::request('/login', $gpt));
        $fixture->answer(AnalyticsFixture::request('/about.md', $gpt), 304, $page);

        self::assertSame(
            [['bot', 'llms', null, 'GPTBot', 'ai_training', null], ['bot', 'markdown', 3, 'Googlebot', 'search', null]],
            array_map(static fn (array $hit): array => [$hit['kind'], $hit['resource_kind'], $hit['page_id'] === null ? null : (int) $hit['page_id'], $hit['bot'], $hit['bot_kind'], $hit['visitor_key']], $fixture->rows(AnalyticsStore::HITS)),
        );
    }

    public function testPagesNotFoundKeepThePathOnlyAndLeaveAssetsAndProbesOut(): void
    {
        $fixture = new AnalyticsFixture();

        $fixture->answer(AnalyticsFixture::request('/old-page?email=ada%40example.org'), 404);
        $fixture->answer(AnalyticsFixture::request('/caf%C3%A9'), 404);
        $fixture->answer(AnalyticsFixture::request('/' . str_repeat('a', 400)), 404);
        foreach (['/favicon.ico', '/wp-login.php', '/.env', '/apple-touch-icon.png'] as $path) {
            $fixture->answer(AnalyticsFixture::request($path), 404);
        }

        $hits = $fixture->rows(AnalyticsStore::HITS);
        self::assertSame(['/old-page', '/café', '/' . str_repeat('a', 299)], array_column($hits, 'path'));
        self::assertSame([null, null, null], array_column($hits, 'visitor_key'));
    }

    public function testSearchesAreCountedWithTheRequestThatMadeThem(): void
    {
        $fixture = new AnalyticsFixture();
        $fixture->pending->add('opening hours', 'en-GB', 2);
        $fixture->pending->add(null, 'en-GB', 0);

        $fixture->answer(AnalyticsFixture::request('/search?q=opening+hours'));
        $fixture->pending->add('broken', 'en-GB', 0);
        $fixture->answer(AnalyticsFixture::request('/search?q=broken'), 500);

        self::assertSame(
            [['search', 'en-GB', 'opening hours', 2], ['search', 'en-GB', null, 0]],
            array_map(static fn (array $hit): array => [$hit['kind'], $hit['locale'], $hit['search_query'], (int) $hit['result_count']], $fixture->rows(AnalyticsStore::HITS)),
        );
        self::assertSame([], $fixture->pending->take());
    }

    public function testOtherAnswersAreNotCounted(): void
    {
        $fixture = new AnalyticsFixture();
        $page = new AnsweredResource();
        $page->page(1, 'en-GB');
        $sitemap = new AnsweredResource();
        $sitemap->file('sitemap');

        $fixture->answer(AnalyticsFixture::request('/'), 301, $page);
        $fixture->answer(AnalyticsFixture::request('/'), 304, $page);
        $fixture->answer(AnalyticsFixture::request('/sitemap.xml'), resource: $sitemap);
        $fixture->answer(AnalyticsFixture::request('/login'));

        self::assertSame([], $fixture->rows(AnalyticsStore::HITS), 'redirects, not-modified, files read by people and screens that are not pages');
    }
}
