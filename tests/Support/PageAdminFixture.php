<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support;

use Psr\Http\Message\ResponseInterface;
use SightPress\Core\Block\Type\GalleryBlock;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Media\MediaRepository;
use SightPress\Core\Media\MediaTypes;
use SightPress\Core\Page\Admin\Editor\BlockData;
use SightPress\Core\Page\Admin\Editor\BlockFormView;
use SightPress\Core\Page\Admin\Editor\BlockOperation;
use SightPress\Core\Page\Admin\Editor\MediaChoices;
use SightPress\Core\Page\Admin\Editor\PageEditorScreen;
use SightPress\Core\Page\Admin\Http\NewPageHandler;
use SightPress\Core\Page\Admin\Http\PageEditorHandler;
use SightPress\Core\Page\Admin\Http\PageListHandler;
use SightPress\Core\Page\Admin\Http\PageWorkflowHandler;
use SightPress\Core\Page\Admin\PageAdmin;
use SightPress\Core\Page\Admin\PageAdminModule;
use SightPress\Core\Page\Publish\PublishChecker;
use SightPress\Core\Page\Workflow\PageWorkflow;
use SightPress\Core\Rbac\PageVoter;
use SightPress\Core\Rbac\VoterAuthorizer;
use SightPress\Core\Template\TemplatePaths;
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

    public function __construct()
    {
        $this->admin = new AdminFixture();
        $this->pages = new PageFixture($this->admin->auth->database, [new GalleryBlock()]);
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
        );
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

    /**
     * Runs a request through a handler as the person, with `{id}` set when given.
     *
     * @param array<string, mixed> $body
     */
    public function handle(PageListHandler|NewPageHandler|PageEditorHandler|PageWorkflowHandler $handler, ?User $user, string $method, string $path, array $body = [], ?int $id = null): ResponseInterface
    {
        $request = $this->admin->request($user, $method, $path, $body, $this->session);
        if ($id !== null) {
            $request = $request->withAttribute(PageAdmin::ID_ATTRIBUTE, (string) $id);
        }

        return $handler->handle($request);
    }
}
