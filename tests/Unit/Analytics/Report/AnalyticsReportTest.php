<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Analytics\Report;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Analytics\Report\AnalyticsReport;
use SightPress\Core\Analytics\Report\AnalyticsReports;
use SightPress\Core\Analytics\Report\DateRange;
use SightPress\Core\Analytics\Report\Summary;
use SightPress\Core\Http\Contract\AnsweredResource;
use SightPress\Core\Tests\Support\Analytics\AnalyticsFixture;

#[CoversClass(AnalyticsReport::class)]
#[CoversClass(AnalyticsReports::class)]
#[CoversClass(Summary::class)]
#[UsesNamespace('SightPress\Core')]
final class AnalyticsReportTest extends TestCase
{
    private AnalyticsFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new AnalyticsFixture(now: '2026-11-14 12:00 UTC');
        $fixture = $this->fixture;
        $fixture->pages->add(1, 'en-GB', 'Home', '/');
        $fixture->pages->add(2, 'en-GB', 'About us', '/about');
        $fixture->pages->add(2, 'pt-PT', 'Sobre nós', '/pt-pt/sobre');
        // Two days ago: rolled up.
        $fixture->view(1, at: '2026-11-12 09:00 UTC');
        $fixture->view(2, at: '2026-11-12 09:05 UTC');
        $fixture->view(1, ip: '192.0.2.20', headers: ['Referer' => 'https://www.bing.com/'], at: '2026-11-12 10:00 UTC');
        $fixture->rollup()->run($fixture->clock->now());
        // Yesterday (not rolled up yet) and today: live.
        $fixture->view(2, 'pt-PT', ['Sec-CH-UA-Mobile' => '?1', 'Referer' => 'https://chatgpt.com/'], at: '2026-11-13 09:00 UTC');
        $fixture->view(1, headers: ['Referer' => 'https://news.example.org/story'], at: '2026-11-14 08:00 UTC');
        $fixture->view(99, ip: '192.0.2.30', at: '2026-11-14 08:10 UTC');
        $fixture->answer(AnalyticsFixture::request('/old'), 404, at: '2026-11-14 08:20 UTC');
        $fixture->answer(AnalyticsFixture::request('/old'), 404, at: '2026-11-14 08:21 UTC');
        $fixture->answer(AnalyticsFixture::request('/gone'), 404, at: '2026-11-14 08:22 UTC');
        $fixture->pending->add('opening hours', 'en-GB', 2);
        $fixture->pending->add('parking', 'en-GB', 0);
        $fixture->pending->add(null, 'en-GB', 0);
        $fixture->answer(AnalyticsFixture::request('/search'), at: '2026-11-14 08:30 UTC');
        $fixture->pending->add('parking', 'en-GB', 0);
        $fixture->answer(AnalyticsFixture::request('/search'), at: '2026-11-14 08:31 UTC');
        $robots = new AnsweredResource();
        $robots->file('robots');
        $fixture->answer(AnalyticsFixture::request('/robots.txt', ['User-Agent' => 'Googlebot/2.1']), resource: $robots, at: '2026-11-14 08:40 UTC');
        $fixture->answer(AnalyticsFixture::request('/robots.txt', ['User-Agent' => 'Googlebot/2.1']), resource: $robots, at: '2026-11-14 08:41 UTC');
        $fixture->answer(AnalyticsFixture::request('/robots.txt', ['User-Agent' => 'GPTBot/1.2']), resource: $robots, at: '2026-11-14 08:42 UTC');
    }

    public function testTheSummaryComparesWithThePreviousPeriod(): void
    {
        $report = $this->fixture->report();
        self::assertSame('2026-11-14', $report->today);
        self::assertSame('Europe/London', $report->timeZone());
        self::assertSame('2026-11-12', $report->firstDay());

        $summary = $report->summary(new DateRange('2026-11-13', '2026-11-14'));
        self::assertSame(['current' => 3, 'previous' => 2, 'change' => 50.0], $summary->visitors->toArray(), 'visitors are counted per day');
        self::assertSame(['current' => 3, 'previous' => 3, 'change' => 0.0], $summary->views->toArray());
        self::assertSame(['current' => 1.0, 'previous' => 1.5, 'change' => -33.3], $summary->viewsPerVisitor->toArray());
        self::assertSame('2026-11-13', $summary->range->from);

        $empty = $report->summary(new DateRange('2025-01-01', '2025-01-07'));
        self::assertSame([0, 0, 0.0, 0.0], [$empty->visitors->current, $empty->views->current, $empty->viewsPerVisitor->current, $empty->views->change()]);
    }

    public function testDailyFiguresHaveEveryDayOfTheRange(): void
    {
        $report = $this->fixture->report();

        self::assertSame([
            ['day' => '2026-11-11', 'visitors' => 0, 'views' => 0],
            ['day' => '2026-11-12', 'visitors' => 2, 'views' => 3],
            ['day' => '2026-11-13', 'visitors' => 1, 'views' => 1],
            ['day' => '2026-11-14', 'visitors' => 2, 'views' => 2],
        ], $report->daily(new DateRange('2026-11-11', '2026-11-14')));
        self::assertSame(['2026-11-08', '2026-11-14'], [$report->lastDays(7)->from, $report->lastDays(7)->to]);
    }

    public function testTopListsAreSortedMostFirst(): void
    {
        $report = $this->fixture->report();
        $range = $report->lastDays(7);

        self::assertSame([
            ['page_id' => 1, 'locale' => 'en-GB', 'title' => 'Home', 'path' => '/', 'views' => 3, 'visitors' => 3],
            ['page_id' => 2, 'locale' => 'en-GB', 'title' => 'About us', 'path' => '/about', 'views' => 1, 'visitors' => 1],
            ['page_id' => 2, 'locale' => 'pt-PT', 'title' => 'Sobre nós', 'path' => '/pt-pt/sobre', 'views' => 1, 'visitors' => 1],
            ['page_id' => 99, 'locale' => 'en-GB', 'title' => '', 'path' => '', 'views' => 1, 'visitors' => 1],
        ], $report->pages($range));
        self::assertCount(2, $report->pages($range, 2));
        self::assertSame([
            ['type' => 'direct', 'source' => '', 'visitors' => 2, 'views' => 3],
            ['type' => 'ai', 'source' => 'ChatGPT', 'visitors' => 1, 'views' => 1],
            ['type' => 'search', 'source' => 'Bing', 'visitors' => 1, 'views' => 1],
            ['type' => 'site', 'source' => 'news.example.org', 'visitors' => 1, 'views' => 1],
        ], $report->sources($range));
        self::assertSame([['locale' => 'en-GB', 'visitors' => 4, 'views' => 5], ['locale' => 'pt-PT', 'visitors' => 1, 'views' => 1]], $report->languages($range));
        self::assertSame([['device' => 'computer', 'visitors' => 4, 'views' => 5], ['device' => 'phone', 'visitors' => 1, 'views' => 1]], $report->devices($range));
        self::assertSame(['searches' => 4, 'without_results' => 3], $report->searchTotals($range));
        self::assertSame([['query' => 'parking', 'searches' => 2, 'without_results' => 2], ['query' => 'opening hours', 'searches' => 1, 'without_results' => 0]], $report->searches($range));
        self::assertSame([['query' => 'parking', 'searches' => 2]], $report->searchesWithoutResults($range));
        self::assertSame([['path' => '/old', 'requests' => 2], ['path' => '/gone', 'requests' => 1]], $report->notFound($range));
        self::assertSame([['path' => '/old', 'requests' => 2]], $report->notFound($range, 1));
        self::assertSame([['bot' => 'Googlebot', 'kind' => 'search', 'resource' => 'robots', 'reads' => 2], ['bot' => 'GPTBot', 'kind' => 'ai_training', 'resource' => 'robots', 'reads' => 1]], $report->bots($range));
        self::assertSame([], $report->notFound(new DateRange('2026-11-12', '2026-11-12')), 'only the days of the range');
    }

    public function testEveryReportIsATable(): void
    {
        $report = $this->fixture->report();
        $range = $report->lastDays(7);

        foreach (AnalyticsReport::TABLES as $name) {
            $table = $report->table($name, $range, 1);
            self::assertSame($name, $table->name);
            foreach ($table->rows as $row) {
                self::assertSame($table->columns, array_keys($row), $name);
            }
        }
        self::assertSame([
            ['figure' => 'visitors', 'current' => 5, 'previous' => 0, 'change_percent' => null],
            ['figure' => 'views', 'current' => 6, 'previous' => 0, 'change_percent' => null],
            ['figure' => 'views_per_visitor', 'current' => 1.2, 'previous' => 0.0, 'change_percent' => null],
        ], $report->table('summary', $range)->rows);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('There is no "visits" report. The reports are: summary, daily, pages');
        $report->table('visits', $range);
    }
}
