<?php

declare(strict_types=1);

namespace SightPress\Core\Analytics;

use SightPress\Core\Analytics\Classification\BotClassifier;
use SightPress\Core\Analytics\Classification\DeviceClassifier;
use SightPress\Core\Analytics\Classification\SourceClassifier;
use SightPress\Core\Analytics\Command\AnalyticsPurgeCommand;
use SightPress\Core\Analytics\Command\AnalyticsReportCommand;
use SightPress\Core\Analytics\Report\AnalyticsReports;
use SightPress\Core\Http\Contract\RequestAnswered;
use SightPress\Core\Site\Contract\ScheduleDue;
use SightPress\Module\Access\Permission;
use SightPress\Module\ModuleInterface;
use SightPress\Module\ModuleManifest;
use SightPress\Module\ModuleRegistrar;
use SightPress\Module\ModuleRequirement;
use SightPress\Module\Search\SearchPerformed;

/**
 * Analytics (ADR-0012, D18–D21): counts pages read, visitors per day, sources, languages, devices, site searches,
 * pages not found and bots, on the server, after the response ({@see AnalyticsRecorder}) — no cookies, no
 * JavaScript, no third parties, and no IP address, user agent or query string kept. `schedule:run` rolls finished
 * days up every hour ({@see AnalyticsRollup}); {@see Report\AnalyticsReport} reads them for the admin and
 * `site:analytics:report`. Optional, on by default.
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
            [new ModuleRequirement('site', '^0.1'), new ModuleRequirement('page', '^0.1'), new ModuleRequirement('rbac', '^0.1')],
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
        foreach ([AnalyticsRollup::class, AnalyticsRetention::class, AnalyticsReports::class] as $class) {
            $services->autowire($class, public: true);
        }

        $permissions = $registrar->permissions();
        $permissions->add(new Permission(self::VIEW, 'View analytics', 'See how many people visit the site, what they read and where they come from.', ['admin', 'editor']));
        $permissions->add(new Permission(self::MANAGE, 'Manage analytics', 'Choose how long analytics are kept, and delete them.', ['admin']));

        $subscribers = $registrar->subscribers();
        $subscribers->listen(RequestAnswered::class, AnalyticsRecorder::class);
        $subscribers->listen(SearchPerformed::class, SearchListener::class);
        $subscribers->listen(ScheduleDue::class, AnalyticsRollupJob::class);

        foreach ([AnalyticsReportCommand::class, AnalyticsPurgeCommand::class] as $command) {
            $registrar->commands()->add($command);
        }
    }
}
