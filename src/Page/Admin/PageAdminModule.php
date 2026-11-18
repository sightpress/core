<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Admin;

use SightPress\Core\Page\Admin\Editor\BlockData;
use SightPress\Core\Page\Admin\Editor\BlockFormView;
use SightPress\Core\Page\Admin\Editor\BlockOperation;
use SightPress\Core\Page\Admin\Editor\MediaChoices;
use SightPress\Core\Page\Admin\Editor\PageEditorScreen;
use SightPress\Core\Page\Admin\Http\NewPageHandler;
use SightPress\Core\Page\Admin\Http\PageEditorHandler;
use SightPress\Core\Page\Admin\Http\PageListHandler;
use SightPress\Core\Page\Admin\Http\PageTranslationHandler;
use SightPress\Core\Page\Admin\Http\PageWorkflowHandler;
use SightPress\Core\Rbac\Contract\AdminAccessMiddleware;
use SightPress\Module\Admin\AdminMenuItem;
use SightPress\Module\ModuleInterface;
use SightPress\Module\ModuleManifest;
use SightPress\Module\ModuleRegistrar;
use SightPress\Module\ModuleRequirement;
use SightPress\Module\Routing\Route;

/**
 * The page screens of the admin panel: the list of pages (a tree, with filters), starting a page, and the page
 * editor with its block editor, search & AI fields, publish checks and workflow buttons. Always on.
 */
final class PageAdminModule implements ModuleInterface
{
    public const string ID = 'page_admin';

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            self::ID,
            '0.1.0',
            [
                new ModuleRequirement('site', '^0.1'),
                new ModuleRequirement('user', '^0.1'),
                new ModuleRequirement('rbac', '^0.1'),
                new ModuleRequirement('media', '^0.1'),
                new ModuleRequirement('block', '^0.1'),
                new ModuleRequirement('page', '^0.1'),
                new ModuleRequirement('admin', '^0.1'),
            ],
            required: true,
        );
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $registrar->views()->add(self::ID, dirname(__DIR__, 3) . '/resources/views/page_admin');

        $services = $registrar->services();
        foreach ([
            PageAdmin::class, BlockData::class, BlockOperation::class, BlockFormView::class, MediaChoices::class,
            PageEditorScreen::class, PageListHandler::class, NewPageHandler::class, PageEditorHandler::class,
            PageWorkflowHandler::class, PageTranslationHandler::class,
        ] as $class) {
            $services->autowire($class);
        }

        $page = PageAdmin::PATH . '/{' . PageAdmin::ID_ATTRIBUTE . ':[0-9]+}';
        $routes = $registrar->routes();
        $routes->add(new Route('page_admin.list', PageAdmin::PATH, PageListHandler::class, middleware: [AdminAccessMiddleware::class]));
        $routes->add(new Route('page_admin.new', NewPageHandler::PATH, NewPageHandler::class, ['GET', 'POST'], [AdminAccessMiddleware::class]));
        $routes->add(new Route('page_admin.edit', $page, PageEditorHandler::class, ['GET', 'POST'], [AdminAccessMiddleware::class]));
        $routes->add(new Route('page_admin.translations', $page . '/translations', PageTranslationHandler::class, ['POST'], [AdminAccessMiddleware::class]));
        $routes->add(new Route('page_admin.workflow', $page . '/workflow', PageWorkflowHandler::class, ['POST'], [AdminAccessMiddleware::class]));
        $registrar->adminMenu()->add(new AdminMenuItem('page_admin.list', 'Pages', 'page_admin.list', 'page.edit.own', position: 10));
    }
}
