<?php

declare(strict_types=1);

namespace SightPress\Core\Analytics\Admin;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Analytics\AnalyticsModule;
use SightPress\Core\Analytics\Report\AnalyticsReport;
use SightPress\Core\Analytics\Report\AnalyticsReports;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Tenant\Site;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

/**
 * `/admin/analytics/download?report=…` (with the screen's range): one report as a CSV file, every row, the same
 * table `site:analytics:report --format=csv` gives. UTF-8; days as `YYYY-MM-DD`, with the site's time zone named in
 * the header row; text a spreadsheet would run as a formula starts with `'`. Needs `analytics.view`.
 */
final readonly class AnalyticsCsvHandler implements RequestHandlerInterface
{
    public const string PATH = '/admin/analytics/download';

    public function __construct(
        private AnalyticsReports $reports,
        private ResponseFactoryInterface $responses,
        private Site $site,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!Actor::fromRequest($request)->has(AnalyticsModule::VIEW)) {
            throw new HttpForbiddenException($request);
        }
        $query = $request->getQueryParams();
        $name = is_string($query['report'] ?? null) ? $query['report'] : '';
        if (!in_array($name, AnalyticsReport::TABLES, true)) {
            throw new HttpNotFoundException($request, 'There is no such report.');
        }
        $report = $this->reports->open();
        $range = AnalyticsRange::fromQuery($query, $report->today)->range;
        $table = $report->table($name, $range);
        $zone = $report->timeZone();
        $header = array_map(static fn (string $column): string => $column === 'day' ? sprintf('day (%s)', $zone) : $column, $table->columns);
        $header[0] .= $name === 'daily' ? '' : sprintf(' (%s to %s, %s)', $range->from, $range->to, $zone);
        $response = $this->responses->createResponse()
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', sprintf('attachment; filename="analytics-%s-%s-%s-to-%s.csv"', $this->site->slug, $name, $range->from, $range->to))
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
        $response->getBody()->write($table->csv($header));

        return $response;
    }
}
