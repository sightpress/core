<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Analytics;

use DateTimeImmutable;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Analytics\AnalyticsRecorder;
use SightPress\Core\Analytics\AnalyticsRetention;
use SightPress\Core\Analytics\AnalyticsRollup;
use SightPress\Core\Analytics\AnalyticsStore;
use SightPress\Core\Analytics\Classification\BotClassifier;
use SightPress\Core\Analytics\Classification\DeviceClassifier;
use SightPress\Core\Analytics\Classification\SourceClassifier;
use SightPress\Core\Analytics\DailyAggregator;
use SightPress\Core\Analytics\PendingSearches;
use SightPress\Core\Analytics\Report\AnalyticsReport;
use SightPress\Core\Analytics\Report\AnalyticsReports;
use SightPress\Core\Http\Contract\AnsweredResource;
use SightPress\Core\Http\Contract\RequestAnswered;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tests\Support\ArraySiteSettings;
use SightPress\Core\Tests\Support\CoreSiteDatabase;
use SightPress\Core\Tests\Support\FixedClock;
use SightPress\Core\Tests\Support\PageFixture;
use SightPress\Core\Tests\Support\RecordingEvents;

/**
 * A site's analytics for unit tests: the analytics tables in an in-memory SQLite database, a site in
 * `Europe/London` on `demo.example`, a clock and the pieces built on them.
 */
final class AnalyticsFixture
{
    public const string BROWSER = 'Mozilla/5.0 (X11; Linux x86_64; rv:140.0) Gecko/20100101 Firefox/140.0';

    public readonly CoreSiteDatabase $database;

    public readonly AnalyticsStore $store;

    public readonly ArraySiteSettings $settings;

    public readonly RecordingEvents $events;

    public readonly FixedClock $clock;

    public readonly Site $site;

    public readonly PendingSearches $pending;

    public readonly FakePageLinks $pages;

    /**
     * @param list<string> $locales
     */
    public function __construct(string $timezone = 'Europe/London', string $now = '2026-11-14 12:00:00 UTC', array $locales = ['en-GB', 'pt-PT'])
    {
        $this->database = new CoreSiteDatabase();
        $this->store = new AnalyticsStore($this->database->connection);
        $this->settings = new ArraySiteSettings();
        $this->events = new RecordingEvents();
        $this->clock = new FixedClock(new DateTimeImmutable($now));
        $site = PageFixture::site($locales);
        $this->site = new Site($site->id, $site->slug, $site->name, $site->status, $site->ownerType, ['demo.example', 'www.demo.example'], $site->databaseDsn, $site->storage, $site->defaultLocale, $site->locales, $timezone, $site->createdAt);
        $this->pending = new PendingSearches();
        $this->pages = new FakePageLinks();
    }

    public function recorder(): AnalyticsRecorder
    {
        return new AnalyticsRecorder($this->store, new BotClassifier(), new DeviceClassifier(), new SourceClassifier(), $this->pending, $this->site);
    }

    public function retention(): AnalyticsRetention
    {
        return new AnalyticsRetention($this->settings, $this->events);
    }

    public function rollup(): AnalyticsRollup
    {
        return new AnalyticsRollup($this->store, new DailyAggregator(), $this->retention(), $this->settings, $this->site);
    }

    public function reports(): AnalyticsReports
    {
        return new AnalyticsReports($this->store, new DailyAggregator(), $this->pages, $this->site, $this->clock);
    }

    public function report(): AnalyticsReport
    {
        return $this->reports()->open();
    }

    /**
     * @param array<string, string> $headers
     */
    public static function request(string $path = '/', array $headers = [], string $method = 'GET', string $ip = '192.0.2.10'): ServerRequestInterface
    {
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);

        return new ServerRequest($method, 'https://demo.example' . $path, ['User-Agent' => self::BROWSER, ...$headers], null, '1.1', ['REMOTE_ADDR' => $ip])
            ->withQueryParams($query);
    }

    /**
     * Tells the recorder about an answered request, at the clock's time unless given.
     */
    public function answer(ServerRequestInterface $request, int $status = 200, ?AnsweredResource $resource = null, bool $signedIn = false, ?string $at = null): void
    {
        $this->recorder()(new RequestAnswered($request, $status, $resource ?? new AnsweredResource(), $signedIn, $at === null ? $this->clock->now() : new DateTimeImmutable($at)));
    }

    /**
     * A person reads a page.
     *
     * @param array<string, string> $headers
     */
    public function view(int $pageId = 1, string $locale = 'en-GB', array $headers = [], string $ip = '192.0.2.10', ?string $at = null, string $path = '/'): void
    {
        $resource = new AnsweredResource();
        $resource->page($pageId, $locale);
        $this->answer(self::request($path, $headers, ip: $ip), resource: $resource, at: $at);
    }

    /**
     * @return list<array<string, int|string|float|bool|null>>
     */
    public function rows(string $table): array
    {
        $rows = $this->database->connection->createQueryBuilder()->select('*')->from($table)->orderBy($table === AnalyticsStore::SALTS ? 'day_on' : 'id')->executeQuery()->fetchAllAssociative();

        return array_map(static fn (array $row): array => array_map(static fn (mixed $value): int|string|float|bool|null => is_scalar($value) ? $value : null, $row), $rows);
    }
}
