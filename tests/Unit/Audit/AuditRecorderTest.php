<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Audit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Audit\AuditEntry;
use SightPress\Core\Audit\AuditFilter;
use SightPress\Core\Audit\AuditLog;
use SightPress\Core\Audit\AuditRecorder;
use SightPress\Core\Infrastructure\Settings\SettingCatalogue;
use SightPress\Core\Media\Contract\MediaChanged;
use SightPress\Core\Media\Contract\MediaDeleted;
use SightPress\Core\Media\Contract\MediaKind;
use SightPress\Core\Media\Contract\MediaUploaded;
use SightPress\Core\Menu\Contract\MenuChanged;
use SightPress\Core\Menu\Contract\MenuName;
use SightPress\Core\Module\CoreModules;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Core\Page\Contract\PageCreated;
use SightPress\Core\Page\Contract\PageMoved;
use SightPress\Core\Page\Contract\PageSaved;
use SightPress\Core\Page\Contract\PageTransition;
use SightPress\Core\Page\Contract\PageTransitioned;
use SightPress\Core\Page\PageLinkQuery;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Rbac\Contract\RoleChanged;
use SightPress\Core\Site\Contract\SettingsChanged;
use SightPress\Core\Tests\Support\BlockFixture;
use SightPress\Core\Tests\Support\FixedClock;
use SightPress\Core\Tests\Support\PageFixture;
use SightPress\Core\User\Contract\AccountChanged;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\UserRepository;

#[CoversClass(AuditRecorder::class)]
#[UsesNamespace('SightPress\Core')]
final class AuditRecorderTest extends TestCase
{
    public function testWritesAPlainSentenceForEveryChange(): void
    {
        $pages = new PageFixture();
        $about = $pages->published('About us');
        $media = new BlockFixture([], $pages->database);
        $picture = $media->addMedia();
        $users = new UserRepository($pages->database->entityManager);
        $zoe = new User('zoe@example.org', 'Zoë', 'Ødegaard', 'UTC', new DateTimeImmutable());
        $users->save($zoe);
        $log = new AuditLog($pages->database->connection);
        $registry = ModuleRegistry::register(new ModuleLoader('0.2.0')->catalogue(CoreModules::candidates())->resolve(new ModuleSelection()));
        $recorder = new AuditRecorder($log, new FixedClock(), new PageLinkQuery($pages->database->entityManager, $pages->addresses()), $media->media(), $users, $pages->site, SettingCatalogue::fromRegistry($registry));
        $page = (int) $about->id();
        $user = (int) $zoe->id();

        foreach ([
            new PageCreated($page, 'en-GB', 3),
            new PageSaved($page, 'en-GB', 2, 3),
            new PageMoved($page, null, 9, 0, 3, 3),
            new PageTransitioned($page, PageTransition::Submit, PageState::Draft, PageState::InReview, 3, 'Ready'),
            new PageTransitioned(999, PageTransition::Reject, PageState::InReview, PageState::Draft, 4),
            new PageTransitioned($page, PageTransition::Approve, PageState::InReview, PageState::Approved, 4),
            new PageTransitioned($page, PageTransition::Publish, PageState::Approved, PageState::Published, 4),
            new PageTransitioned($page, PageTransition::Unpublish, PageState::Published, PageState::Draft, 4),
            new \SightPress\Core\Page\Contract\TranslationPublished($page, 'pt-PT', true, 4, 3),
            new \SightPress\Core\Page\Contract\TranslationPublished($page, 'pt-PT', false, 5, 3),
            new MediaUploaded($picture, 'k', MediaKind::Image, 3),
            new MediaChanged($picture, 3, ['alt', 'caption']),
            new MediaChanged(999, 3, []),
            new MediaDeleted($picture, 'lighthouse.jpg', 3),
            new MenuChanged(MenuName::Main, MenuChanged::MOVED_UP, 5, $page, null, 4),
            new MenuChanged(MenuName::Footer, MenuChanged::ADDED, 6, null, 'https://example.org', 4),
            new MenuChanged(MenuName::Footer, MenuChanged::REMOVED, 6, null, null, 4),
            new RoleChanged($user, 1, null, 'editor'),
            new RoleChanged(999, 1, 'editor', 'admin'),
            new AccountChanged($user, $user, ['display_name', 'avatar']),
            new SettingsChanged(['site.name', 'site.contact_email', 'branding.logo', 'branding.icon', 'branding.share_image', 'site.default_locale', 'site.locales', 'site.default_timezone', 'acme.other'], 1),
            new \stdClass(),
        ] as $event) {
            $recorder($event);
        }

        $entries = array_reverse($log->find(new AuditFilter(), 50));
        self::assertSame([
            'Started the page “About us”.',
            'Saved “About us” (revision 2).',
            'Moved “About us”.',
            'Sent “About us” for review.',
            'Sent page 999 back to draft.',
            'Approved “About us”.',
            'Published “About us”.',
            'Unpublished “About us”.',
            'Published the European Portuguese translation of “About us”.',
            'Made the European Portuguese translation of “About us” a draft again.',
            'Uploaded “lighthouse.jpg”.',
            'Changed the alt, caption of “lighthouse.jpg”.',
            'Changed the details of file 999.',
            'Deleted “lighthouse.jpg”.',
            'Main menu: Moved up the entry for “About us”.',
            'Footer menu: Added the link to https://example.org.',
            'Footer menu: Removed the link to an address.',
            'Changed the role of Zoë from none to editor.',
            'Changed the role of account 999 from editor to admin.',
            'Changed the display name, avatar of Zoë.',
            'Changed the site settings: Site name, Contact email address, Logo, Icon, Share picture, Main language, Languages, Time zone, acme.other.',
        ], array_map(static fn (AuditEntry $e): string => $e->summary, $entries));
        self::assertSame(['page.submit', 'page', (string) $page, 3, ['from' => 'draft', 'to' => 'in_review', 'comment' => 'Ready']], [$entries[3]->action, $entries[3]->entityType, $entries[3]->entityId, $entries[3]->actorId, $entries[3]->details]);
        self::assertSame(['settings', null], [$entries[20]->entityType, $entries[20]->entityId]);
        self::assertSame(['menu.moved_up', 'main'], [$entries[14]->action, $entries[14]->entityId]);
        self::assertCount(12, AuditRecorder::EVENTS);
    }
}
