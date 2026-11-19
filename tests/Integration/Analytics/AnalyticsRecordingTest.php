<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Analytics;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use SightPress\Core\Analytics\AnalyticsRollup;
use SightPress\Core\Analytics\AnalyticsStore;
use SightPress\Core\Analytics\Report\AnalyticsReports;
use SightPress\Core\Analytics\Report\DateRange;
use SightPress\Core\Auth\PasswordHasher;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Rbac\RoleRepository;
use SightPress\Core\Search\SearchIndex;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteModules;
use SightPress\Core\Tests\Support\Browser;
use SightPress\Core\Tests\Support\FixedClock;
use SightPress\Core\Tests\Support\KernelSite;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\UserRepository;

/**
 * AN-1 acceptance through the kernel: what is and isn't counted, bots apart from visitors, visitors once per day,
 * nothing that identifies a person left after the roll-up (canary values scanned in every table), the retention
 * purge, a broken recording that doesn't change the response, and nothing written while analytics is off.
 */
#[CoversNothing]
final class AnalyticsRecordingTest extends TestCase
{
    private const string PASSWORD = 'Correct horse battery staple 7!';
    private const string CANARY_IP = '203.0.113.77';
    private const string CANARY_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) CanaryBrowser/9.9 ua-canary-7f3e';
    private const string CANARY_QUERY = 'canary-query-9d1c';
    private const string CANARY_REFERRER = 'https://ref.example/referrer-path-canary-5b2a?secret=1';

    private KernelSite $site;

    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->site = new KernelSite('analytics_recording');
        $this->clock = new FixedClock(new DateTimeImmutable('2026-11-14 12:00 UTC'));
        $tenant = $this->site->kernel->container();
        \assert($tenant instanceof \DI\Container);
        $tenant->set(ClockInterface::class, $this->clock);
        $editor = Services::get($this->site->container(), PageEditor::class);
        foreach ([['Home', 'home', 'Welcome to the library.'], ['Opening hours', 'hours', 'When the library is open.']] as [$title, $slug, $summary]) {
            $editor->create('en-GB', new PageContent($title, $slug, $summary))->changeState(PageState::Published, new DateTimeImmutable());
        }
        Services::get($this->site->container(), PageRepository::class)->flush();
        Services::get($this->site->container(), SearchIndex::class)->reindex();
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testCountsPeopleOncePerDayAndBotsApart(): void
    {
        self::assertSame(200, $this->get('/')->getStatusCode());
        $this->get('/hours');
        $this->get('/hours', ip: '192.0.2.99');
        $this->get('/hours', ['User-Agent' => 'Mozilla/5.0 (compatible; GPTBot/1.2; +https://openai.com/gptbot)']);
        $this->get('/llms.txt', ['User-Agent' => 'Mozilla/5.0 (compatible; Claude-User/1.0)']);
        $this->get('/hours.md', ['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1)']);
        $this->get('/no-such-page?x=1');
        self::assertSame(200, $this->get('/search?q=opening')->getStatusCode());

        $this->clock->advance(86400);
        $this->get('/');

        $report = Services::get($this->site->container(), AnalyticsReports::class)->open();
        $range = new DateRange('2026-11-14', '2026-11-15');
        self::assertSame([['day' => '2026-11-14', 'visitors' => 2, 'views' => 3], ['day' => '2026-11-15', 'visitors' => 1, 'views' => 1]], $report->daily($range));
        self::assertSame(3, $report->summary($range)->visitors->current, 'the same person on two days counts twice');
        self::assertSame(
            [['Claude-User', 'ai_search', 'llms'], ['GPTBot', 'ai_training', 'page'], ['Googlebot', 'search', 'markdown']],
            array_map(static fn (array $row): array => [$row['bot'], $row['kind'], $row['resource']], $report->bots($range)),
        );
        self::assertSame([['path' => '/no-such-page', 'requests' => 1]], $report->notFound($range));
        self::assertSame([['query' => 'opening', 'searches' => 1, 'without_results' => 0]], $report->searches($range));
    }

    public function testRequestsThatMustNotBeCountedAreNot(): void
    {
        $this->get('/', ['Sec-GPC' => '1']);
        $this->get('/', ['DNT' => '1']);
        $this->get('/', ['Sec-Purpose' => 'prefetch']);
        $this->get('/', method: 'HEAD');
        $this->get('/admin');
        $this->get('/assets/default/site.css');
        $this->get('/assets/default/missing.css');
        $this->get('/search?q=opening', ['DNT' => '1']);
        $browser = $this->signedIn();
        self::assertSame(200, $browser->request('GET', '/hours')->getStatusCode());
        $browser->request('GET', '/search?q=opening');

        self::assertSame([], $this->rows(AnalyticsStore::HITS));
    }

    public function testNothingThatIdentifiesAPersonIsLeftAfterTheRollUp(): void
    {
        $headers = ['User-Agent' => self::CANARY_AGENT, 'Referer' => self::CANARY_REFERRER];
        $this->get('/hours?' . self::CANARY_QUERY . '=1', $headers, self::CANARY_IP);
        $this->get('/' . self::CANARY_QUERY . '-missing?' . self::CANARY_QUERY . '=2', $headers, self::CANARY_IP);
        $this->get('/search?q=ada%40example.org+' . self::CANARY_QUERY, $headers, self::CANARY_IP);
        self::assertCount(3, $this->rows(AnalyticsStore::HITS));
        self::assertCount(1, $this->rows(AnalyticsStore::SALTS));

        $this->clock->advance(86400);
        $rollup = Services::get($this->site->container(), AnalyticsRollup::class);
        self::assertSame(['2026-11-14'], $rollup->run($this->clock->now())['days']);

        self::assertSame([], $this->rows(AnalyticsStore::HITS), 'no raw hit from a finished day');
        self::assertSame([], $this->rows(AnalyticsStore::SALTS), 'no salt from a finished day');
        $connection = Services::get($this->site->container(), Connection::class);
        foreach ($connection->createSchemaManager()->listTableNames() as $table) {
            $columns = array_map(static fn ($column): string => $column->getObjectName()->toString(), $connection->createSchemaManager()->introspectTableColumnsByUnquotedName($table));
            foreach ($columns as $column) {
                self::assertDoesNotMatchRegularExpression('/(^|_)(ip|ip_address|user_agent|useragent|query_string|referr?er)$/', $column, sprintf('%s.%s', $table, $column));
            }
            $dump = json_encode($connection->createQueryBuilder()->select('*')->from($table)->executeQuery()->fetchAllAssociative(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            foreach ([self::CANARY_IP, 'ua-canary-7f3e', 'CanaryBrowser', 'referrer-path-canary', 'secret=1', self::CANARY_QUERY . '=', 'ada@example.org'] as $canary) {
                self::assertStringNotContainsString($canary, $dump, sprintf('"%s" found in %s', $canary, $table));
            }
        }
        $report = Services::get($this->site->container(), AnalyticsReports::class)->open();
        self::assertSame([['type' => 'site', 'source' => 'ref.example', 'visitors' => 1, 'views' => 1]], $report->sources(new DateRange('2026-11-14', '2026-11-14')));
        self::assertSame([['path' => '/' . self::CANARY_QUERY . '-missing', 'requests' => 1]], $report->notFound(new DateRange('2026-11-14', '2026-11-14')), 'the path is kept, never the query string');
        self::assertSame(['searches' => 1, 'without_results' => 1], $report->searchTotals(new DateRange('2026-11-14', '2026-11-14')), 'counted, but not listed');
        self::assertSame([], $report->searches(new DateRange('2026-11-14', '2026-11-14')));
    }

    public function testTheRetentionPurgeDropsOldFigures(): void
    {
        $store = Services::get($this->site->container(), AnalyticsStore::class);
        $store->finishDay('2024-10-13', [['metric' => 'views', 'dimension' => '', 'item' => '', 'value' => 9]]);
        $store->finishDay('2024-10-14', [['metric' => 'views', 'dimension' => '', 'item' => '', 'value' => 4]]);

        self::assertSame(1, Services::get($this->site->container(), AnalyticsRollup::class)->run($this->clock->now())['purged']);
        self::assertSame(['2024-10-14'], array_column($this->rows(AnalyticsStore::DAILY), 'day_on'));
    }

    public function testABrokenRecordingDoesNotChangeTheResponse(): void
    {
        $expected = (string) $this->get('/hours')->getBody();
        Services::get($this->site->container(), Connection::class)->createSchemaManager()->dropTable('analytics_hit');

        $response = $this->get('/hours', ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) Firefox/141.0']);

        self::assertSame([200, $expected], [$response->getStatusCode(), (string) $response->getBody()]);
    }

    public function testNothingIsWrittenWhileAnalyticsIsOff(): void
    {
        $tenant = $this->site->kernel->container();
        Services::get($tenant, SiteModules::class)->turnOff($this->site->site(), 'analytics', null);
        Services::get($tenant, SiteContainerFactory::class)->evict('demo');

        self::assertSame(200, $this->get('/hours')->getStatusCode());
        $this->get('/nothing');
        $this->get('/search?q=opening');

        self::assertSame([], $this->rows(AnalyticsStore::HITS));
        self::assertSame([], $this->rows(AnalyticsStore::SALTS));
    }

    /**
     * @param array<string, string> $headers
     */
    private function get(string $path, array $headers = [], string $ip = '192.0.2.10', string $method = 'GET'): ResponseInterface
    {
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
        $request = new ServerRequest($method, 'https://example.org' . $path, ['User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:140.0) Gecko/20100101 Firefox/140.0', ...$headers], null, '1.1', ['REMOTE_ADDR' => $ip])
            ->withQueryParams($query);
        $response = $this->site->kernel->handle($request);
        $this->site->kernel->terminate();

        return $response;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $table): array
    {
        return Services::get($this->site->container(), Connection::class)->createQueryBuilder()->select('*')->from($table)->executeQuery()->fetchAllAssociative();
    }

    private function signedIn(): Browser
    {
        $container = $this->site->container();
        $user = new User('max@example.org', 'Max', 'Mustermann', 'Europe/London', new DateTimeImmutable());
        $user->setPasswordHash(new PasswordHasher(['memory_cost' => 8, 'time_cost' => 1, 'threads' => 1])->hash(self::PASSWORD), new DateTimeImmutable());
        Services::get($container, UserRepository::class)->save($user);
        $roles = Services::get($container, RoleRepository::class);
        $roles->assign((int) $user->id(), $roles->findRole('member') ?? self::fail('member'));
        $browser = new Browser($this->site->kernel, 'https://example.org');
        self::assertSame(303, $browser->submit('/login', ['email' => 'max@example.org', 'password' => self::PASSWORD])->getStatusCode());

        return $browser;
    }
}
