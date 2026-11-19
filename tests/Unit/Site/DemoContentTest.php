<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Site;

use DI\Container;
use Psr\Clock\ClockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Auth\Contract\VerificationCodes;
use SightPress\Core\Auth\Provisioning\DemoAccounts;
use SightPress\Core\Block\Type\AudioBlock;
use SightPress\Core\Block\Type\FileDownloadBlock;
use SightPress\Core\Block\Type\GalleryBlock;
use SightPress\Core\Block\Type\VideoBlock;
use SightPress\Core\Contact\ContactFormBlock;
use SightPress\Core\Media\Admin\Provisioning\DemoMedia;
use SightPress\Core\Media\MediaEditor;
use SightPress\Core\Media\MediaException;
use SightPress\Core\Media\MediaUploader;
use SightPress\Core\Menu\Contract\MenuName;
use SightPress\Core\Menu\MenuEditor;
use SightPress\Core\Menu\MenuNode;
use SightPress\Core\Menu\Provisioning\DemoMenu;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Page\PageRedirects;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Page\Provisioning\DemoPages;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Rbac\Contract\RoleGrants;
use SightPress\Core\Rbac\RoleGrantPolicy;
use SightPress\Core\Site\Contract\FirstAdmin;
use SightPress\Core\Site\Contract\SiteProvisioning;
use SightPress\Core\Tests\Support\AuthFixture;
use SightPress\Core\Tests\Support\MediaFixture;
use SightPress\Core\Tests\Support\PageFixture;
use SightPress\Core\Tests\Support\RecordingEvents;
use SightPress\Core\User\AccountRegistrar;
use SightPress\Core\User\Contract\PendingAccounts;

#[CoversClass(DemoMedia::class)]
#[CoversClass(DemoAccounts::class)]
#[CoversClass(DemoPages::class)]
#[CoversClass(DemoMenu::class)]
#[CoversClass(SiteProvisioning::class)]
#[UsesNamespace('SightPress\Core')]
final class DemoContentTest extends TestCase
{
    private AuthFixture $auth;

    private MediaFixture $media;

    private PageFixture $pages;

    private MenuEditor $menus;

    protected function setUp(): void
    {
        $this->auth = new AuthFixture();
        $this->media = new MediaFixture(database: $this->auth->database);
        $this->pages = new PageFixture($this->auth->database, [new GalleryBlock(), new VideoBlock(), new AudioBlock(), new FileDownloadBlock(), new ContactFormBlock($this->auth->templates)]);
        $this->menus = new MenuEditor($this->auth->database->entityManager, new RecordingEvents());
    }

    protected function tearDown(): void
    {
        $this->media->remove();
    }

    public function testNothingHappensWithoutTheDemoFlag(): void
    {
        $event = $this->event(false);
        foreach ([new DemoMedia(), new DemoAccounts(), new DemoPages(), new DemoMenu()] as $step) {
            $step($event);
        }

        self::assertSame([], $event->lines());
        self::assertSame([], $this->media->repository()->newest(10));
    }

    public function testAddsMediaAccountsAPageWithEveryBlockAndMenuEntries(): void
    {
        $event = $this->event(true);
        $event->setAdminId((int) $this->auth->user('ada@example.org', 'admin')->id());
        foreach ([new DemoMedia(), new DemoAccounts(), new DemoPages(), new DemoMenu()] as $step) {
            $step($event);
        }

        $files = $this->media->repository()->newest(10);
        self::assertCount(5, $files);
        foreach ($files as $file) {
            self::assertTrue($file->isPublished(), $file->originalName());
        }
        $picture = $this->media->repository()->find((int) $event->shared('demo.picture'));
        self::assertSame('A test pattern: coloured bars, a gradient and circles.', $picture?->altText('en-GB'));
        self::assertNotNull($this->media->repository()->find((int) $event->shared('demo.audio'))?->transcript('en-GB'));

        foreach (['bea@example.org' => 'editor', 'cleo@example.org' => 'contributor', 'max@example.org' => 'member'] as $email => $role) {
            $user = $this->auth->users->findByEmail($email);
            self::assertNotNull($user);
            self::assertTrue($user->isPending());
            self::assertSame([$role], array_map(static fn ($r) => $r->name(), $this->auth->database->roles()->rolesOf((int) $user->id())));
        }
        self::assertMatchesRegularExpression('/^Demo editor Bea: Give the setup code \d{3} \d{3} to bea@example.org\./', $event->lines()[1]);

        $page = $this->pages->repository()->find((int) $event->shared('demo.page'));
        self::assertNotNull($page);
        self::assertSame(PageState::Published, $page->state());
        $types = array_values(array_unique(array_map(static fn (array $block): string => $block['type'], $page->translation('en-GB')?->blocks() ?? [])));
        self::assertSame(['heading', 'rich_text', 'image', 'call_to_action', 'columns', 'gallery', 'video', 'audio', 'file_download', 'contact_form'], $types);
        $subpage = $this->pages->repository()->find((int) $event->shared('demo.subpage'));
        self::assertSame(['every-block/under', $event->shared('demo.contributor')], [$subpage?->translation('en-GB')?->path(), $subpage?->authorId()]);

        $members = $this->pages->repository()->find((int) $event->shared('demo.members'));
        self::assertSame([PageState::Published, false, 'for-members'], [$members?->state(), $members?->isPublic(), $members?->translation('en-GB')?->path()]);
        $old = $this->pages->repository()->findByPath('en-GB', 'old-news');
        self::assertSame(PageState::Trash, $old?->page()->state());
        foreach ([$page, $subpage, $members] as $translated) {
            self::assertTrue($translated?->translation('pt-PT')?->isPublished(), 'copied into pt-PT and published there');
        }
        self::assertSame('every-block/under', $subpage?->translation('pt-PT')?->path());
        self::assertSame('every-block', $this->pages->redirects()->find('en-GB', 'visit')?->target());

        $main = $this->menus->tree(MenuName::Main);
        self::assertSame([$page->id(), $members?->id()], array_map(static fn (MenuNode $node) => $node->item->pageId(), $main));
        self::assertSame([$subpage->id()], array_map(static fn (MenuNode $node) => $node->item->pageId(), $main[0]->children));
        self::assertSame([
            'Added the demo page "Every kind of block" (/every-block) and a page under it.',
            'Added a page for members only (/for-members), a page in the trash (/old-news) and a redirect from /visit to /every-block.',
            'Copied the demo pages into pt-PT and published them there, to translate.',
        ], array_slice($event->lines(), 4));
    }

    public function testPagesNeedTheirMediaAndAMissingFileIsSaid(): void
    {
        $event = $this->event(true);
        try {
            new DemoPages()($event);
            self::fail('The pages need the media first.');
        } catch (\LogicException $exception) {
            self::assertSame('The demo picture is missing.', $exception->getMessage());
        }
        new DemoMenu()($event);
        self::assertSame([], $this->menus->tree(MenuName::Main), 'no page, no menu entry');

        $this->expectException(MediaException::class);
        $this->expectExceptionMessage('The demo file harbour.jpg could not be copied.');
        new DemoMedia($this->media->directory->path . '/nowhere')($event);
    }

    private function event(bool $demo): SiteProvisioning
    {
        $auth = $this->auth;

        return new SiteProvisioning($this->pages->site, new Container([
            MediaUploader::class => $this->media->uploader(),
            MediaEditor::class => new MediaEditor($this->media->repository(), $this->media->files(), $this->media->clock, new RecordingEvents()),
            PendingAccounts::class => new AccountRegistrar($auth->users, $auth->avatars(), $auth->clock),
            RoleGrants::class => new RoleGrantPolicy($auth->database->roles(), new RecordingEvents()),
            VerificationCodes::class => $auth->codes(),
            PageEditor::class => $this->pages->editor(),
            PageRepository::class => $this->pages->repository(),
            PageRedirects::class => $this->pages->redirects(),
            ClockInterface::class => $this->pages->clock,
            MenuEditor::class => $this->menus,
        ]), new FirstAdmin('ada@example.org', 'Ada', 'Lovelace'), 'https://demo.example', $demo);
    }
}
