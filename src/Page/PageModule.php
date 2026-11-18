<?php

declare(strict_types=1);

namespace SightPress\Core\Page;

use SightPress\Core\Page\Contract\MarkdownPages;
use SightPress\Core\Page\Contract\PageIndex;
use SightPress\Core\Page\Contract\StarterContent;
use SightPress\Core\Page\Contract\PageLinks;
use SightPress\Core\Page\Contract\PageOverview;
use SightPress\Core\Page\Http\PublicPageHandler;
use SightPress\Core\Page\Provisioning\DemoPages;
use SightPress\Core\Page\Provisioning\StarterPages;
use SightPress\Core\Site\Contract\SiteProvisioning;
use SightPress\Core\Page\Contract\PageTransitioned;
use SightPress\Core\Page\Contract\PublicPages;
use SightPress\Core\Page\Publish\PublishChecker;
use SightPress\Core\Page\Workflow\PageWorkflow;
use SightPress\Core\Page\Workflow\ReviewRequestMailer;
use SightPress\Module\ModuleInterface;
use SightPress\Module\ModuleManifest;
use SightPress\Module\ModuleRegistrar;
use SightPress\Module\ModuleRequirement;
use SightPress\Module\Routing\Route;

/**
 * Pages: the `pages` tree, a translation per locale with its blocks, and a revision for every save. The
 * {@see PageEditor} creates, writes and moves pages; the {@see PageRepository} finds them; the {@see PageWorkflow}
 * moves them from draft to review to published (once the {@see PublishChecker} finds no errors), and asks editors
 * for a review by email; the {@see PublicPageHandler} serves the public site (`/` and `/{path}`); new sites get
 * the {@see StarterPages}. Always on. Its
 * permissions (`page.*`) are in core's catalogue, and the RBAC module's page voter decides who may do what.
 */
final class PageModule implements ModuleInterface
{
    public const string ID = 'page';

    public const string PATH_ATTRIBUTE = 'path';

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            self::ID,
            '0.1.0',
            [
                new ModuleRequirement('site', '^0.1'),
                new ModuleRequirement('rbac', '^0.1'),
                new ModuleRequirement('block', '^0.1'),
                new ModuleRequirement('theme', '^0.1'),
            ],
            required: true,
        );
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $registrar->migrations()->add(new PageMigrations());

        $services = $registrar->services();
        $services->autowire(PageRepository::class, public: true);
        $services->autowire(PageAddresses::class, public: true);
        $services->autowire(PageLinkQuery::class, PageLinks::class, public: true);
        $services->autowire(PageIndexQuery::class, PageIndex::class, public: true);
        $services->autowire(PageMarkdown::class, MarkdownPages::class, public: true);
        $services->autowire(PublicPageHandler::class);
        $services->autowire(PageEditor::class, public: true);
        $services->autowire(StarterContent::class, public: true);
        $services->autowire(PublishedPages::class, PublicPages::class, public: true);
        $services->autowire(PageOverviewQuery::class, PageOverview::class, public: true);
        $services->autowire(PublishChecker::class, public: true);
        $services->autowire(PageWorkflow::class, public: true);
        $services->autowire(ReviewRequestMailer::class);
        $services->autowire(StarterPages::class);
        $services->autowire(DemoPages::class);

        $registrar->views()->add(self::ID, dirname(__DIR__, 2) . '/resources/views/page');
        $registrar->subscribers()->listen(PageTransitioned::class, ReviewRequestMailer::class);
        $registrar->subscribers()->listen(SiteProvisioning::class, StarterPages::class, SiteProvisioning::CONTENT);
        $registrar->subscribers()->listen(SiteProvisioning::class, DemoPages::class, DemoPages::PRIORITY);

        $routes = $registrar->routes();
        $routes->add(new Route('page.home', '/', PublicPageHandler::class, ['GET', 'HEAD']));
        $routes->add(new Route('page.show', '/{' . self::PATH_ATTRIBUTE . ':.+}', PublicPageHandler::class, ['GET', 'HEAD']));
    }
}
