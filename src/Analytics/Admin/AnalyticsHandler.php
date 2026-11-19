<?php

declare(strict_types=1);

namespace SightPress\Core\Analytics\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Analytics\AnalyticsModule;
use SightPress\Core\Analytics\AnalyticsRetention;
use SightPress\Core\Analytics\Chart\BarChart;
use SightPress\Core\Analytics\Chart\ChartFormat;
use SightPress\Core\Analytics\Chart\ChartRenderer;
use SightPress\Core\Analytics\Chart\ChartView;
use SightPress\Core\Analytics\Chart\LineChart;
use SightPress\Core\Analytics\Report\AnalyticsReport;
use SightPress\Core\Analytics\Report\AnalyticsReports;
use SightPress\Core\Analytics\Report\DateRange;
use SightPress\Core\Analytics\Report\Figure;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Template\LanguageName;
use SightPress\Core\Template\Message;
use SightPress\Core\Template\Translations;
use SightPress\Core\Template\ViewerPreferences;
use SightPress\Core\Tenant\EnabledModules;
use SightPress\Core\Tenant\Site;
use Slim\Exception\HttpForbiddenException;

/**
 * `/admin/analytics`: the site's analytics for a range of days (presets as links with `aria-current`, or a custom
 * range form), headline figures as text, then one section with a heading per report — visitors over time (a chart
 * with its summary and daily table), pages, sources, languages (sites with several), devices, site search (while it
 * is on), pages not found (with "Add a redirect") and search engines and AI crawlers — each with a CSV download.
 * People with `analytics.manage` also choose how long analytics are kept and can delete them. Needs `analytics.view`.
 */
final readonly class AnalyticsHandler implements RequestHandlerInterface
{
    public const string PATH = '/admin/analytics';

    /** Rows of each top list on the screen; the CSV has them all. */
    public const int TOP = 10;

    public function __construct(
        private AdminScreens $screens,
        private AnalyticsReports $reports,
        private AnalyticsRetention $retention,
        private ChartView $charts,
        private ChartRenderer $renderer,
        private Translations $translations,
        private EnabledModules $modules,
        private Site $site,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = Actor::fromRequest($request);
        if (!$actor->has(AnalyticsModule::VIEW)) {
            throw new HttpForbiddenException($request);
        }
        $report = $this->reports->open();
        $chosen = AnalyticsRange::fromQuery($request->getQueryParams(), $report->today);
        $range = $chosen->range;
        $locale = $this->locale($request);
        $format = new ChartFormat($locale);
        $summary = $report->summary($range);
        $days = $range->days();
        $daily = $report->daily($range);
        $before = $report->daily($range->previous());
        $visitors = new Message('{count, plural, one {# visitor} other {# visitors}}');
        $chart = $this->charts->line(
            new LineChart('analytics-visitors', new Message('Visitors'), $visitors, array_column($daily, 'day'), array_column($daily, 'visitors'), array_column($before, 'visitors')),
            $locale,
            $chosen->name(),
            new Message('The {days, plural, one {day before} other {# days before}}', ['days' => $days]),
        );
        $devices = $report->devices($range);

        return $this->screens->render($request, '@analytics/screen.html.twig', [
            'presets' => array_map(static fn (string $preset): array => [
                'name' => AnalyticsRange::presetName($preset),
                'url' => self::PATH . '?range=' . $preset,
                'current' => $chosen->preset === $preset,
            ], AnalyticsRange::PRESETS),
            'form' => $chosen->form,
            'range' => $range,
            'range_name' => $chosen->name(),
            'range_text' => new Message('{from} to {to}', ['from' => $format->longDay($range->from), 'to' => $format->longDay($range->to)]),
            'zone' => $report->timeZone(),
            'query' => http_build_query($chosen->query()),
            'headlines' => [
                $this->headline($summary->visitors, new Message('{count, plural, one {# visitor} other {# visitors}}', ['count' => $summary->visitors->current]), $days, $format),
                $this->headline($summary->views, new Message('{count, plural, one {# page view} other {# page views}}', ['count' => $summary->views->current]), $days, $format),
                $this->headline($summary->viewsPerVisitor, new Message('{count, number} page views per visitor', ['count' => $summary->viewsPerVisitor->current]), $days, $format),
            ],
            'chart' => $chart,
            'pages' => $this->pages($report, $range, $format),
            'sources' => $this->rows(array_map(static fn (array $row): array => ['label' => AnalyticsLabels::source($row['type'], $row['source']), 'values' => [$row['visitors']]], $report->sources($range, self::TOP)), $format),
            'languages' => count($this->site->locales) > 1
                ? $this->rows(array_map(static fn (array $row): array => ['label' => new LanguageName($row['locale']), 'lang' => $row['locale'], 'values' => [$row['visitors'], $row['views']]], $report->languages($range)), $format)
                : null,
            'devices' => $this->charts->bar(
                new BarChart('analytics-devices', new Message('Devices'), $visitors, array_map(fn (array $row): array => ['label' => $this->translations->translate(AnalyticsLabels::device($row['device']), [], $locale), 'value' => $row['visitors']], $devices)),
                $locale,
                new Message('Device'),
            ),
            'search' => $this->modules->has('search') ? [
                'totals' => $report->searchTotals($range),
                'queries' => $this->rows(array_map(static fn (array $row): array => ['label' => $row['query'], 'values' => [$row['searches'], $row['without_results']]], $report->searches($range, self::TOP)), $format),
                'empty' => $this->rows(array_map(static fn (array $row): array => ['label' => $row['query'], 'values' => [$row['searches']]], $report->searchesWithoutResults($range, self::TOP)), $format),
            ] : null,
            'not_found' => $this->rows(array_map(static fn (array $row): array => ['label' => $row['path'], 'redirect' => '/admin/redirects?' . http_build_query(['from' => $row['path']]), 'values' => [$row['requests']]], $report->notFound($range, self::TOP)), $format),
            'can_redirect' => $actor->has('page.edit.any'),
            'bots' => array_map(static fn (array $row): array => [
                'bot' => $row['bot'],
                'kind' => AnalyticsLabels::botKind($row['kind']),
                'resource' => AnalyticsLabels::resource($row['resource']),
                'reads' => $format->number($row['reads']),
            ], $report->bots($range, 20)),
            'manage' => $actor->has(AnalyticsModule::MANAGE) ? [
                'form' => RetentionHandler::form($this->retention->months()),
                'months' => $this->retention->months(),
            ] : null,
        ]);
    }

    /**
     * One headline figure as text: "1,349 visitors, up 12% on the 30 days before", with the direction for an arrow.
     *
     * @param Message $amount the figure's number in words, "1,349 visitors"
     *
     * @return array{text: Message, direction: string}
     */
    private function headline(Figure $figure, Message $amount, int $days, ChartFormat $format): array
    {
        $change = $figure->change();

        return [
            'text' => new Message('{amount}, {change}', [
                'amount' => $amount,
                'change' => AnalyticsLabels::change($change, $days, $format->percent($change ?? 0.0)),
            ]),
            'direction' => match (true) {
                $change === null || $change > 0 => 'up',
                $change < 0 => 'down',
                default => 'same',
            },
        ];
    }

    /**
     * @return list<array{label: Message|LanguageName|string, url: string, lang: ?string, redirect: ?string, values: list<string>, bar: string}>
     */
    private function pages(AnalyticsReport $report, DateRange $range, ChartFormat $format): array
    {
        return $this->rows(array_map(static fn (array $row): array => [
            'label' => $row['title'] !== '' ? $row['title'] : new Message('A page that was deleted (number {id})', ['id' => $row['page_id']]),
            'url' => $row['path'],
            'lang' => $row['locale'],
            'values' => [$row['views'], $row['visitors']],
        ], $report->pages($range, self::TOP)), $format);
    }

    /**
     * Rows of a top list: their numbers written for the viewer and a decorative bar for the first number.
     *
     * @param list<array{label: Message|LanguageName|string, values: list<int>, url?: string, lang?: string, redirect?: string}> $rows
     *
     * @return list<array{label: Message|LanguageName|string, url: string, lang: ?string, redirect: ?string, values: list<string>, bar: string}>
     */
    private function rows(array $rows, ChartFormat $format): array
    {
        $max = max([0, ...array_map(static fn (array $row): int => $row['values'][0] ?? 0, $rows)]);

        return array_map(fn (array $row): array => [
            'label' => $row['label'],
            'url' => $row['url'] ?? '',
            'lang' => $row['lang'] ?? null,
            'redirect' => $row['redirect'] ?? null,
            'values' => array_map($format->number(...), $row['values']),
            'bar' => $this->renderer->inlineBar($row['values'][0] ?? 0, $max),
        ], $rows);
    }

    /**
     * The person's admin language, as the admin panel uses it.
     */
    private function locale(ServerRequestInterface $request): string
    {
        $own = $request->getAttribute(ViewerPreferences::class);
        $locale = $own instanceof ViewerPreferences ? ($own->locale ?? '') : '';

        return $this->translations->catalogueLocale($locale !== '' ? $locale : $this->translations->defaultLocale());
    }
}
