<?php

declare(strict_types=1);

namespace SightPress\Core\Page;

use SightPress\Core\Page\Contract\PageTransitioned;
use SightPress\Core\Page\Contract\PublicPages;
use SightPress\Core\Page\Workflow\PageWorkflow;
use SightPress\Core\Page\Workflow\ReviewRequestMailer;
use SightPress\Module\ModuleInterface;
use SightPress\Module\ModuleManifest;
use SightPress\Module\ModuleRegistrar;
use SightPress\Module\ModuleRequirement;

/**
 * Pages: the `pages` tree, a translation per locale with its blocks, and a revision for every save. The
 * {@see PageEditor} creates, writes and moves pages; the {@see PageRepository} finds them; the {@see PageWorkflow}
 * moves them from draft to review to published, and asks editors for a review by email. Always on. Its
 * permissions (`page.*`) are in core's catalogue, and the RBAC module's page voter decides who may do what.
 */
final class PageModule implements ModuleInterface
{
    public const string ID = 'page';

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            self::ID,
            '0.1.0',
            [new ModuleRequirement('site', '^0.1'), new ModuleRequirement('rbac', '^0.1'), new ModuleRequirement('block', '^0.1')],
            required: true,
        );
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $registrar->migrations()->add(new PageMigrations());

        $services = $registrar->services();
        $services->autowire(PageRepository::class, public: true);
        $services->autowire(PageEditor::class, public: true);
        $services->autowire(PublishedPages::class, PublicPages::class, public: true);
        $services->autowire(PageWorkflow::class, public: true);
        $services->autowire(ReviewRequestMailer::class);

        $registrar->views()->add(self::ID, dirname(__DIR__, 2) . '/resources/views/page');
        $registrar->subscribers()->listen(PageTransitioned::class, ReviewRequestMailer::class);
    }
}
