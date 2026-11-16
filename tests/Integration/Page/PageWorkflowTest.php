<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Page;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Page\Contract\PageTransition;
use SightPress\Core\Page\Contract\PublicPages;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Page\Workflow\PageWorkflow;
use SightPress\Core\Page\Workflow\WorkflowException;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Rbac\RoleRepository;
use SightPress\Core\Tests\Support\KernelSite;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\UserRepository;

/**
 * The page workflow through a real site container: a contributor submits (the review email listener runs against the
 * real roles and accounts), an editor approves and publishes, the page becomes public, and is unpublished again;
 * the states survive a fresh entity manager.
 */
#[CoversNothing]
final class PageWorkflowTest extends TestCase
{
    private KernelSite $site;

    protected function setUp(): void
    {
        $this->site = new KernelSite('workflow');
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testAPageGoesFromDraftToPublishedAndBack(): void
    {
        $container = $this->site->container();
        $contributor = $this->actor('cy@example.org', 'contributor');
        $editor = $this->actor('ed@example.org', 'editor');
        $workflow = Services::get($container, PageWorkflow::class);
        $pages = Services::get($container, PageRepository::class);
        $public = Services::get($container, PublicPages::class);
        $page = Services::get($container, PageEditor::class)->create('en-GB', new PageContent('Opening times'), null, $contributor->userId);

        $workflow->apply($page, PageTransition::Submit, $contributor);
        try {
            $workflow->apply($page, PageTransition::Approve, $contributor);
            self::fail('A contributor can\'t approve.');
        } catch (WorkflowException $exception) {
            self::assertTrue($exception->forbidden);
        }
        $workflow->apply($page, PageTransition::Approve, $editor);
        self::assertNull($public->findByUrl('opening-times'), 'approved pages are not public yet');
        $workflow->apply($page, PageTransition::Publish, $editor);

        Services::get($container, EntityManagerInterface::class)->clear();
        self::assertSame(PageState::Published, $pages->find((int) $page->id())?->state());
        $found = $public->findByUrl('/opening-times');
        self::assertNotNull($found, 'published pages are public');
        self::assertSame('Opening times', $found->title);

        $published = $pages->find((int) $page->id());
        self::assertNotNull($published);
        $workflow->apply($published, PageTransition::Unpublish, $editor, 'Out of date');
        self::assertNull($public->findByUrl('/opening-times/'));
    }

    private function actor(string $email, string $role): Actor
    {
        $container = $this->site->container();
        $user = new User($email, 'Test', 'Person', 'UTC', new DateTimeImmutable());
        $user->setPasswordHash('$argon2id$v=19$m=8,t=1,p=1$c2FsdHNhbHQ$aGFzaA', new DateTimeImmutable());
        Services::get($container, UserRepository::class)->save($user);
        $roles = Services::get($container, RoleRepository::class);
        $roles->assign((int) $user->id(), $roles->findRole($role) ?? self::fail($role));

        return new Actor((int) $user->id(), $roles->permissionNamesOf((int) $user->id()));
    }
}
