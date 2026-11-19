<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Analytics\Command;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Analytics\AnalyticsRetention;
use SightPress\Core\Analytics\AnalyticsRollup;
use SightPress\Core\Analytics\AnalyticsStore;
use SightPress\Core\Analytics\Command\AnalyticsPurgeCommand;
use SightPress\Core\Analytics\Command\AnalyticsReportCommand;
use SightPress\Core\Analytics\Command\SiteAnalyticsCommand;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteModules;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tests\Support\ArrayCommandInput;
use SightPress\Core\Tests\Support\KernelSite;
use SightPress\Core\Tests\Support\RecordingOutput;

#[CoversClass(SiteAnalyticsCommand::class)]
#[CoversClass(AnalyticsReportCommand::class)]
#[CoversClass(AnalyticsPurgeCommand::class)]
#[UsesNamespace('SightPress\Core')]
final class AnalyticsCommandsTest extends TestCase
{
    private KernelSite $site;

    protected function setUp(): void
    {
        $this->site = new KernelSite('analytics_commands');
        $store = Services::get($this->site->container(), AnalyticsStore::class);
        $store->finishDay('2026-11-01', [
            ['metric' => 'views', 'dimension' => '', 'item' => '', 'value' => 12],
            ['metric' => 'visitors', 'dimension' => '', 'item' => '', 'value' => 8],
            ['metric' => 'not_found', 'dimension' => 'path', 'item' => '=cmd|"/c calc"', 'value' => 2],
            ['metric' => 'not_found', 'dimension' => 'path', 'item' => '/old', 'value' => 1],
        ]);
        $store->finishDay('2026-10-31', [['metric' => 'views', 'dimension' => '', 'item' => '', 'value' => 6], ['metric' => 'visitors', 'dimension' => '', 'item' => '', 'value' => 4]]);
        $store->finishDay('2020-01-01', [['metric' => 'views', 'dimension' => '', 'item' => '', 'value' => 1]]);
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testShowsAReportAsATableCsvOrJson(): void
    {
        $report = $this->command(AnalyticsReportCommand::class);
        self::assertSame(['site:analytics:report', 5], [$report->name(), count($report->parameters())]);
        self::assertNotSame('', $report->description());

        [$code, $output] = $this->execute($report, ['from' => '2026-11-01', 'to' => '2026-11-01']);
        self::assertSame(0, $code);
        self::assertSame([
            'From 2026-11-01 to 2026-11-01 (days in Europe/London).',
            '',
            'summary',
            'figure             current  previous  change_percent',
            'visitors           8        4         100',
            'views              12       6         100',
            'views_per_visitor  1.5      1.5       0',
        ], $output->lines);
        self::assertSame(['Warning: analytics were last rolled up never. Check that bin/sightpress schedule:run runs every minute.'], $output->errors);

        Services::get($this->site->container(), AnalyticsRollup::class)->run(new DateTimeImmutable());
        [, $output] = $this->execute($report, ['from' => '2026-11-01', 'to' => '2026-11-01', 'report' => 'not-found', 'format' => 'csv']);
        self::assertSame(["path,requests\r\n\"'=cmd|\"\"/c calc\"\"\",2\r\n/old,1"], $output->lines);
        self::assertSame([], $output->errors, 'rolled up just now');

        [, $output] = $this->execute($report, ['from' => '2026-10-31', 'to' => '2026-11-01', 'report' => 'all', 'format' => 'json']);
        $json = json_decode($output->lines[0], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        self::assertSame(['site_time_zone' => 'Europe/London', 'from' => '2026-10-31', 'to' => '2026-11-01'], array_slice($json, 0, 3));
        self::assertIsArray($json['reports']);
        self::assertSame(['summary', 'daily', 'pages', 'sources', 'languages', 'devices', 'searches', 'searches-without-results', 'not-found', 'bots'], array_keys($json['reports']));
        self::assertSame([['day' => '2026-10-31', 'visitors' => 4, 'views' => 6], ['day' => '2026-11-01', 'visitors' => 8, 'views' => 12]], $json['reports']['daily']);
        self::assertIsArray($json['reports']['summary']);
        self::assertSame(['figure' => 'views_per_visitor', 'current' => 1.5, 'previous' => 0.0, 'change_percent' => null], $json['reports']['summary'][2]);

        [, $output] = $this->execute($report, ['report' => 'bots']);
        self::assertSame('bots', $output->lines[2]);
        self::assertSame('Nothing counted.', $output->lines[3], 'the last 30 days by default');
    }

    public function testRefusesWhatItCannotShow(): void
    {
        $report = $this->command(AnalyticsReportCommand::class);

        self::assertSame([1, ['Use --format=table, csv or json, not "xml".']], self::errors($this->execute($report, ['format' => 'xml'])));
        self::assertSame([1, ['There is no "visits" report. The reports are: summary, daily, pages, sources, languages, devices, searches, searches-without-results, not-found, bots, or all.']], self::errors($this->execute($report, ['report' => 'visits'])));
        self::assertSame([1, ['A CSV holds one report: choose it with --report.']], self::errors($this->execute($report, ['report' => 'all', 'format' => 'csv'])));
        self::assertSame([1, ['"yesterday" isn\'t a date written as YYYY-MM-DD.']], self::errors($this->execute($report, ['from' => 'yesterday'])));
        self::assertSame([1, ['There is no site "shop". See the sites with site:list.']], self::errors($this->execute($report, [], 'shop')));

        $tenant = $this->site->kernel->container();
        Services::get($tenant, SiteModules::class)->turnOff($this->site->site(), 'analytics', null);
        Services::get($tenant, SiteContainerFactory::class)->evict('demo');
        self::assertSame([1, ['Analytics is off for the site "demo". Turn it on with: site:module:enable demo analytics']], self::errors($this->execute($this->command(AnalyticsReportCommand::class), [])));
    }

    public function testPurgesBeforeADayOrWhatIsOlderThanTheRetention(): void
    {
        $purge = $this->command(AnalyticsPurgeCommand::class);
        self::assertSame(['site:analytics:purge', 2], [$purge->name(), count($purge->parameters())]);
        self::assertNotSame('', $purge->description());

        [$code, $output] = $this->execute($purge, []);
        $before = new DateTimeImmutable('today')->modify('-25 months')->format('Y-m-d');
        self::assertSame([0, ['The site keeps analytics for 25 months.', 'Deleted 1 daily figure from before ' . $before . '.']], [$code, $output->lines]);
    }

    public function testPurgesBeforeAGivenDay(): void
    {
        $purge = $this->command(AnalyticsPurgeCommand::class);
        Services::get($this->site->container(), AnalyticsRetention::class)->change(1, null);

        self::assertSame([1, ['"soon" isn\'t a date written as YYYY-MM-DD.']], self::errors($this->execute($purge, ['before' => 'soon'])));
        [$code, $output] = $this->execute($purge, ['before' => '2026-11-01']);
        self::assertSame([0, ['Deleted 3 daily figures from before 2026-11-01.']], [$code, $output->lines]);
        [, $output] = $this->execute($purge, []);
        self::assertSame('The site keeps analytics for 1 month.', $output->lines[0]);
    }

    /**
     * @template T of SiteAnalyticsCommand
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function command(string $class): SiteAnalyticsCommand
    {
        $tenant = $this->site->kernel->container();

        return new $class(Services::get($tenant, SiteRepository::class), Services::get($tenant, SiteContainerFactory::class));
    }

    /**
     * @param array<string, string> $options
     *
     * @return array{int, RecordingOutput}
     */
    private function execute(SiteAnalyticsCommand $command, array $options, string $slug = 'demo'): array
    {
        $output = new RecordingOutput();

        return [$command->run(new ArrayCommandInput(['slug' => $slug], $options), $output), $output];
    }

    /**
     * @param array{int, RecordingOutput} $result
     *
     * @return array{int, list<string>}
     */
    private static function errors(array $result): array
    {
        return [$result[0], $result[1]->errors];
    }
}
