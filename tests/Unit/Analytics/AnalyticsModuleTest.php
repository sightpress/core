<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Analytics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Analytics\AnalyticsMigrations;
use SightPress\Core\Analytics\AnalyticsModule;
use SightPress\Core\Analytics\AnalyticsRecorder;
use SightPress\Core\Analytics\AnalyticsRollupJob;
use SightPress\Core\Analytics\Command\AnalyticsPurgeCommand;
use SightPress\Core\Analytics\Command\AnalyticsReportCommand;
use SightPress\Core\Analytics\Report\AnalyticsReports;
use SightPress\Core\Analytics\SearchListener;
use SightPress\Core\Http\Contract\RequestAnswered;
use SightPress\Core\Module\CoreModules;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Core\Site\Contract\ScheduleDue;
use SightPress\Module\Search\SearchPerformed;

#[CoversClass(AnalyticsModule::class)]
#[CoversClass(AnalyticsMigrations::class)]
#[UsesNamespace('SightPress\Core')]
final class AnalyticsModuleTest extends TestCase
{
    public function testIsOptionalOnByDefaultAndRegistersItsListenersPermissionsAndCommands(): void
    {
        $manifest = new AnalyticsModule()->manifest();
        self::assertSame(['analytics', false, true], [$manifest->id, $manifest->required, $manifest->enabledByDefault]);
        self::assertSame(['site', 'page', 'rbac', 'admin'], array_map(static fn ($requirement) => $requirement->moduleId, $manifest->requires));

        $candidates = array_values(array_filter(CoreModules::candidates(), static fn ($candidate) => $candidate->module instanceof AnalyticsModule));
        self::assertSame('Analytics', $candidates[0]->about['analytics']->name);
        self::assertStringContainsString('No cookies', $candidates[0]->about['analytics']->description);

        $registry = ModuleRegistry::register(new ModuleLoader('0.2.0')->catalogue(CoreModules::candidates())->resolve(new ModuleSelection()));
        self::assertTrue($registry->services()[AnalyticsReports::class]->item->public);
        $listeners = array_map(static fn ($l) => [$l->item->event, $l->item->listener], array_values(array_filter($registry->listeners(), static fn ($l) => $l->module === 'analytics')));
        self::assertSame([[RequestAnswered::class, AnalyticsRecorder::class], [SearchPerformed::class, SearchListener::class], [ScheduleDue::class, AnalyticsRollupJob::class]], $listeners);
        $permissions = array_values(array_filter($registry->permissions(), static fn ($p) => $p->module === 'analytics'));
        self::assertSame(
            [['analytics.view', ['admin', 'editor']], ['analytics.manage', ['admin']]],
            array_map(static fn ($p) => [$p->item->name, $p->item->defaultRoles], $permissions),
        );
        self::assertSame(
            [AnalyticsReportCommand::class, AnalyticsPurgeCommand::class],
            array_values(array_map(static fn ($c) => $c->item, array_filter($registry->commands(), static fn ($c) => $c->module === 'analytics'))),
        );

        $routes = array_values(array_filter($registry->routes(), static fn ($r) => $r->module === 'analytics'));
        self::assertSame(['/admin/analytics', '/admin/analytics/download', '/admin/analytics/retention', '/admin/analytics/delete'], array_map(static fn ($r) => $r->item->path, $routes));
        self::assertSame([\SightPress\Core\Analytics\Admin\AnalyticsWidget::class], array_values(array_map(static fn ($w) => $w->item, array_filter($registry->dashboardWidgets(), static fn ($w) => $w->module === 'analytics'))));
        $views = array_values(array_filter($registry->views(), static fn ($v) => $v->module === 'analytics'));
        self::assertFileExists($views[0]->item->directory . '/charts.html.twig');

        $migrations = new AnalyticsMigrations();
        self::assertSame('SightPress\Core\Analytics\Migrations', $migrations->namespace());
        self::assertDirectoryExists($migrations->directory());
    }
}
