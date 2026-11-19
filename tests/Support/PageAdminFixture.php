<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support;

use SightPress\Core\Tests\Support\Assistant\NoAssistant;
use Psr\Http\Message\ResponseInterface;
use SightPress\Core\Block\Type\GalleryBlock;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Media\MediaRepository;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Core\Media\MediaTypes;
use SightPress\Core\Page\Admin\Editor\BlockData;
use SightPress\Core\Page\Admin\Editor\BlockFormView;
use SightPress\Core\Page\Admin\Editor\BlockOperation;
use SightPress\Core\Page\Admin\Editor\MediaChoices;
use SightPress\Core\Page\Admin\Editor\PageEditorScreen;
use SightPress\Core\Page\Admin\Http\NewPageHandler;
use SightPress\Core\Page\Admin\Http\PageEditorHandler;
use SightPress\Core\Page\Admin\Http\PageListHandler;
use SightPress\Core\Page\Admin\Http\PageOperationsHandler;
use SightPress\Core\Page\Admin\Http\PagePurgeHandler;
use SightPress\Core\Page\Admin\Http\PageRevisionHandler;
use SightPress\Core\Page\Admin\Http\PageRevisionsHandler;
use SightPress\Core\Page\Admin\Http\PageScheduleHandler;
use SightPress\Core\Page\Admin\Http\PageTranslationHandler;
use SightPress\Core\Page\Admin\Http\PageWorkflowHandler;
use SightPress\Core\Page\Admin\Http\RedirectsHandler;
use SightPress\Core\Page\Admin\PageAdmin;
use SightPress\Core\Page\Admin\PageAdminModule;
use SightPress\Core\Page\Publish\PublishChecker;
use SightPress\Core\Page\Revision\RevisionDiff;
use SightPress\Core\Page\Revision\RevisionHistory;
use SightPress\Core\Page\Workflow\PageWorkflow;
use SightPress\Core\Rbac\PageVoter;
use SightPress\Core\Rbac\VoterAuthorizer;
use SightPress\Core\Template\TemplatePaths;
use SightPress\Core\Tenant\EnabledModules;
use SightPress\Core\Theme\ThemeLoader;
use SightPress\Core\Theme\ThemePageColours;
use SightPress\Core\User\Entity\User;

/**
 * The page screens of the admin panel ({@see PageAdminModule}) for unit tests, built from real services on one
 * migrated site database in memory: pages, blocks (core's and the gallery), media, roles and the admin shell.
 */
final class PageAdminFixture
{
    public readonly AdminFixture $admin;

    public readonly PageFixture $pages;

    public readonly BlockFixture $blocks;

    public readonly Session $session;

    /**
     * @param list<string> $locales the site's languages, the default first
     * @param list<string> $modules the modules that are on, for screens that show more when they are
     */
    public function __construct(array $locales = ['en-GB', 'pt-PT'], private readonly array $modules = ['search'])
    {
        $this->admin = new AdminFixture();
        $this->pages = new PageFixture($this->admin->auth->database, [new GalleryBlock()], $locales);
        $this->blocks = new BlockFixture([new GalleryBlock()], $this->admin->auth->database);
        $this->session = new Session();
    }

    public function authorizer(): VoterAuthorizer
    {
        return new VoterAuthorizer([new PageVoter()]);
    }

    public function media(): MediaRepository
    {
        return $this->blocks->media();
    }

    public function pageAdmin(): PageAdmin
    {
        return new PageAdmin($this->pages->repository(), $this->admin->auth->twig);
    }

    public function workflow(): PageWorkflow
    {
        return new PageWorkflow($this->pages->repository(), $this->authorizer(), $this->pages->clock, $this->pages, $this->checks());
    }

    public function checks(): PublishChecker
    {
        return new PublishChecker(
            $this->blocks->validator(),
            $this->blocks->renderer(),
            $this->blocks->contexts($this->pages->site),
            $this->media(),
            $this->pages->repository(),
            new ThemePageColours(new ThemeLoader()->load(TemplatePaths::defaultTheme())),
            $this->pages->site,
            $this->blocks->texts($this->pages->site),
        );
    }

    public function screen(): PageEditorScreen
    {
        $choices = new MediaChoices($this->media(), new MediaTypes());

        return new PageEditorScreen(
            $this->admin->shell(),
            $this->pageAdmin(),
            $this->pages->addresses(),
            $this->workflow(),
            $this->checks(),
            $this->authorizer(),
            new BlockFormView($this->blocks->types, $choices),
            $this->blocks->types,
            $this->blocks->validator(),
            $this->blocks->renderer(),
            $this->blocks->contexts($this->pages->site),
            $choices,
            $this->pages->site,
            $this->pages->repository(),
            $this->pages->database->settings(),
            self::modules(...$this->modules),
            NoAssistant::access(),
        );
    }

    /**
     * Which modules the site has on, for screens that show something only while another module is on.
     */
    public static function modules(string ...$ids): EnabledModules
    {
        $candidates = array_map(static fn (string $id): ModuleCandidate => new ModuleCandidate(new FakeModule($id), 'sightpress/core'), $ids);

        return new EnabledModules(ModuleRegistry::register(new ModuleLoader('0.2.0')->catalogue(array_values($candidates))->resolve(new ModuleSelection())));
    }

    public function listHandler(): PageListHandler
    {
        return new PageListHandler($this->admin->shell(), $this->pages->repository(), $this->pages->addresses(), $this->authorizer(), $this->admin->auth->users, $this->pages->site);
    }

    public function newHandler(): NewPageHandler
    {
        return new NewPageHandler($this->admin->shell(), $this->admin->auth->validator(), $this->pages->repository(), $this->pages->editor(), $this->pageAdmin(), $this->pages->site);
    }

    public function editorHandler(): PageEditorHandler
    {
        return new PageEditorHandler(
            $this->admin->shell(),
            $this->pageAdmin(),
            $this->screen(),
            $this->admin->auth->validator(),
            $this->pages->editor(),
            new BlockData($this->blocks->types),
            new BlockOperation($this->blocks->types),
            $this->authorizer(),
            $this->pages->clock,
            $this->pages->site,
        );
    }

    public function workflowHandler(): PageWorkflowHandler
    {
        return new PageWorkflowHandler($this->admin->shell(), $this->pageAdmin(), $this->screen(), $this->workflow(), $this->pages->site);
    }

    public function translationHandler(): PageTranslationHandler
    {
        return new PageTranslationHandler($this->admin->shell(), $this->pageAdmin(), $this->screen(), $this->pages->editor(), $this->checks(), $this->authorizer(), $this->pages->site);
    }

    public function operationsHandler(): PageOperationsHandler
    {
        return new PageOperationsHandler($this->admin->shell(), $this->pageAdmin(), $this->pages->editor(), $this->pages->repository(), $this->pages->addresses(), $this->authorizer(), $this->pages->site);
    }

    public function purgeHandler(): PagePurgeHandler
    {
        return new PagePurgeHandler($this->admin->shell(), $this->pageAdmin(), $this->pages->editor(), $this->pages->repository(), $this->authorizer(), $this->pages->site);
    }

    public function redirectsHandler(): RedirectsHandler
    {
        return new RedirectsHandler($this->admin->shell(), $this->admin->auth->validator(), $this->pages->redirects(), $this->pages->addresses(), $this->pages->site);
    }

    public function revisionsHandler(): PageRevisionsHandler
    {
        return new PageRevisionsHandler($this->admin->shell(), $this->pageAdmin(), new RevisionHistory($this->pages->repository()), $this->authorizer(), $this->admin->auth->users, $this->pages->site);
    }

    public function revisionHandler(): PageRevisionHandler
    {
        return new PageRevisionHandler($this->admin->shell(), $this->pageAdmin(), new RevisionHistory($this->pages->repository()), new RevisionDiff($this->blocks->types), $this->pages->editor(), $this->authorizer(), $this->admin->auth->users, $this->pages->site);
    }

    public function pageSchedule(): \SightPress\Core\Page\Schedule\PageSchedule
    {
        return new \SightPress\Core\Page\Schedule\PageSchedule($this->pages->repository(), $this->workflow(), $this->admin->auth->database->roles(), $this->pages->clock, $this->pages, $this->pages->site);
    }

    public function scheduleHandler(): PageScheduleHandler
    {
        return new PageScheduleHandler($this->admin->shell(), $this->pageAdmin(), $this->pageSchedule(), $this->authorizer(), $this->pages->site);
    }

    /**
     * Runs a request through a handler as the person, with `{id}` set when given.
     *
     * @param array<string, mixed> $body
     */
    public function handle(\Psr\Http\Server\RequestHandlerInterface $handler, ?User $user, string $method, string $path, array $body = [], ?int $id = null): ResponseInterface
    {
        $request = $this->admin->request($user, $method, $path, $body, $this->session);
        if ($id !== null) {
            $request = $request->withAttribute(PageAdmin::ID_ATTRIBUTE, (string) $id);
        }

        return $handler->handle($request);
    }
}
