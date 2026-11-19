<?php

declare(strict_types=1);

namespace SightPress\Core\Analytics;

use SightPress\Core\Analytics\Admin\AnalyticsCsvHandler;
use SightPress\Core\Analytics\Admin\AnalyticsHandler;
use SightPress\Core\Analytics\Admin\AnalyticsWidget;
use SightPress\Core\Analytics\Admin\DeleteAnalyticsHandler;
use SightPress\Core\Analytics\Admin\RetentionHandler;
use SightPress\Core\Analytics\Chart\ChartRenderer;
use SightPress\Core\Analytics\Chart\ChartSummary;
use SightPress\Core\Analytics\Chart\ChartView;
use SightPress\Core\Analytics\Classification\BotClassifier;
use SightPress\Core\Analytics\Classification\DeviceClassifier;
use SightPress\Core\Analytics\Classification\SourceClassifier;
use SightPress\Core\Analytics\Command\AnalyticsPurgeCommand;
use SightPress\Core\Analytics\Command\AnalyticsReportCommand;
use SightPress\Core\Analytics\Report\AnalyticsReports;
use SightPress\Core\Http\Contract\RequestAnswered;
use SightPress\Core\Site\Contract\ScheduleDue;
use SightPress\Core\Rbac\Contract\AdminAccessMiddleware;
use SightPress\Module\Access\Permission;
use SightPress\Module\Admin\AdminMenuItem;
use SightPress\Module\ModuleInterface;
use SightPress\Module\ModuleManifest;
use SightPress\Module\ModuleRegistrar;
use SightPress\Module\ModuleRequirement;
use SightPress\Module\Routing\Route;
use SightPress\Module\Search\SearchPerformed;

/**
 * Analytics (ADR-0012, D18–D21): counts pages read, visitors per day, sources, languages, devices, site searches,
 * pages not found and bots, on the server, after the response ({@see AnalyticsRecorder}) — no cookies, no
 * JavaScript, no third parties, and no IP address, user agent or query string kept. `schedule:run` rolls finished
 * days up every hour ({@see AnalyticsRollup}); {@see Report\AnalyticsReport} reads them for the admin and
 * `site:analytics:report`, and {@see ChartView} draws them as accessible SVG charts with summaries and tables, on
 * the admin's Analytics screen ({@see AnalyticsHandler}) and the dashboard's "Last 7 days" ({@see AnalyticsWidget}).
 * Optional, on by default.
 */
final class AnalyticsModule implements ModuleInterface
{
    public const string ID = 'analytics';

    public const string VIEW = 'analytics.view';

    public const string MANAGE = 'analytics.manage';

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            self::ID,
            '0.1.0',
            [new ModuleRequirement('site', '^0.1'), new ModuleRequirement('page', '^0.1'), new ModuleRequirement('rbac', '^0.1'), new ModuleRequirement('admin', '^0.1')],
            enabledByDefault: true,
        );
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $registrar->migrations()->add(new AnalyticsMigrations());

        $services = $registrar->services();
        foreach ([
            BotClassifier::class, DeviceClassifier::class, SourceClassifier::class, QueryRedactor::class, PendingSearches::class,
            AnalyticsStore::class, DailyAggregator::class, AnalyticsRecorder::class, SearchListener::class, AnalyticsRollupJob::class,
        ] as $class) {
            $services->autowire($class);
        }
        foreach ([ChartRenderer::class, ChartSummary::class] as $class) {
            $services->autowire($class);
        }
        $registrar->views()->add(self::ID, dirname(__DIR__, 2) . '/resources/views/analytics');
        foreach ([AnalyticsRollup::class, AnalyticsRetention::class, AnalyticsReports::class, ChartView::class] as $class) {
            $services->autowire($class, public: true);
        }

        $permissions = $registrar->permissions();
        $permissions->add(new Permission(self::VIEW, 'View analytics', 'See how many people visit the site, what they read and where they come from.', ['admin', 'editor']));
        $permissions->add(new Permission(self::MANAGE, 'Manage analytics', 'Choose how long analytics are kept, and delete them.', ['admin']));

        $subscribers = $registrar->subscribers();
        $subscribers->listen(RequestAnswered::class, AnalyticsRecorder::class);
        $subscribers->listen(SearchPerformed::class, SearchListener::class);
        $subscribers->listen(ScheduleDue::class, AnalyticsRollupJob::class);

        foreach ([AnalyticsHandler::class, AnalyticsCsvHandler::class, RetentionHandler::class, DeleteAnalyticsHandler::class, AnalyticsWidget::class] as $class) {
            $services->autowire($class);
        }
        $routes = $registrar->routes();
        $routes->add(new Route('analytics.overview', AnalyticsHandler::PATH, AnalyticsHandler::class, middleware: [AdminAccessMiddleware::class]));
        $routes->add(new Route('analytics.download', AnalyticsCsvHandler::PATH, AnalyticsCsvHandler::class, middleware: [AdminAccessMiddleware::class]));
        $routes->add(new Route('analytics.retention', RetentionHandler::PATH, RetentionHandler::class, ['POST'], [AdminAccessMiddleware::class]));
        $routes->add(new Route('analytics.delete', DeleteAnalyticsHandler::PATH, DeleteAnalyticsHandler::class, ['GET', 'POST'], [AdminAccessMiddleware::class]));
        $registrar->adminMenu()->add(new AdminMenuItem('analytics.overview', 'Analytics', 'analytics.overview', self::VIEW, position: 15));
        $registrar->dashboard()->add(AnalyticsWidget::class);

        foreach ([AnalyticsReportCommand::class, AnalyticsPurgeCommand::class] as $command) {
            $registrar->commands()->add($command);
        }
    }
}
