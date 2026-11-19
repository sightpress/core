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
#[CoversClass(\SightPress\Core\Page\Contract\PageCloned::class)]
#[CoversClass(\SightPress\Core\Page\Contract\PagePurged::class)]
#[CoversClass(\SightPress\Core\Page\Contract\RedirectChanged::class)]
#[CoversClass(\SightPress\Core\Page\Contract\RevisionRestored::class)]
#[CoversClass(\SightPress\Core\Page\Contract\PageScheduled::class)]
#[CoversClass(\SightPress\Core\Page\Contract\ScheduledStepFailed::class)]
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
        $recorder = new AuditRecorder($log, new FixedClock(), new PageLinkQuery($pages->database->entityManager, $pages->addresses(), $pages->database->settings()), $media->media(), $users, $pages->site, SettingCatalogue::fromRegistry($registry));
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
            new SettingsChanged(['site.name', 'site.contact_email', 'branding.logo', 'branding.icon', 'branding.share_image', 'analytics.retention_months', 'site.default_locale', 'site.locales', 'site.default_timezone', 'acme.other'], 1),
            new PageTransitioned($page, PageTransition::Archive, PageState::Published, PageState::Archived, 4),
            new PageTransitioned($page, PageTransition::Restore, PageState::Archived, PageState::Draft, 4),
            new PageTransitioned($page, PageTransition::Trash, PageState::Draft, PageState::Trash, 4),
            new PageTransitioned($page, PageTransition::Untrash, PageState::Trash, PageState::Draft, 4),
            new \SightPress\Core\Page\Contract\PageCloned($page, $page, 4),
            new \SightPress\Core\Page\Contract\PagePurged(41, 'Old news', [41], 4),
            new \SightPress\Core\Page\Contract\PagePurged(42, 'Archive', [42, 43, 44], 4),
            new \SightPress\Core\Page\Contract\RedirectChanged(\SightPress\Core\Page\Contract\RedirectChanged::ADDED, 'pt-PT', 'antigo', 'novo', 4),
            new \SightPress\Core\Page\Contract\RedirectChanged(\SightPress\Core\Page\Contract\RedirectChanged::REMOVED, 'en-GB', 'old', null, 4),
            new \SightPress\Core\Page\Contract\RedirectChanged(\SightPress\Core\Page\Contract\RedirectChanged::REMOVED, 'en-GB', 'old', 'new', 4),
            new \SightPress\Core\Page\Contract\RevisionRestored($page, 'pt-PT', 2, 5, 4),
            new \SightPress\Core\Page\Contract\PageScheduled($page, new \DateTimeImmutable('2026-12-01 09:00:00'), new \DateTimeImmutable('2026-12-31 18:00:00'), 4),
            new \SightPress\Core\Page\Contract\PageScheduled($page, null, new \DateTimeImmutable('2026-12-31 18:00:00'), 4),
            new \SightPress\Core\Page\Contract\PageScheduled($page, null, null, 4),
            new \SightPress\Core\Page\Contract\ScheduledStepFailed($page, PageTransition::Publish, 'the publish checks found errors', 4),
            new \SightPress\Core\Page\Contract\ScheduledStepFailed($page, PageTransition::Unpublish, 'no permission', null),
            new \SightPress\Core\Auth\Contract\AccountRegistered($user, 'member'),
            new \SightPress\Core\Auth\Contract\AccountRegistered($user, 'member', 'editor'),
            new SettingsChanged(['people.registration', 'rbac.registration_role', 'site.visibility'], 1),
            new \SightPress\Core\Tenant\SiteModuleChanged('acme.events', 'Events', \SightPress\Core\Tenant\SiteModuleChanged::ENABLED, 1),
            new \SightPress\Core\Tenant\SiteModuleChanged('acme.events', 'Events', \SightPress\Core\Tenant\SiteModuleChanged::DISABLED, 1),
            new \SightPress\Core\Tenant\SiteModuleChanged('acme.events', 'Events', \SightPress\Core\Tenant\SiteModuleChanged::DATA_DELETED, null),
            new \SightPress\Core\Analytics\Contract\AnalyticsDataDeleted(1),
            new \SightPress\Core\Assistant\Contract\AssistantUsed(4, 'seo_meta', 'anthropic', 'claude-haiku-5-5', 300, 40, 'ok'),
            new \SightPress\Core\Assistant\Contract\AssistantUsed(null, 'connection_test', 'openai', 'gpt-5', 0, 0, 'invalid_key'),
            new SettingsChanged(['assistant.provider', 'assistant.model', 'assistant.base_url', 'assistant.key', 'assistant.monthly_budget', 'assistant.roles'], 1),
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
            'Changed the site settings: Site name, Contact email address, Logo, Icon, Share picture, How long analytics are kept, Main language, Languages, Time zone, acme.other.',
            'Archived “About us”.',
            'Restored “About us” as a draft.',
            'Put “About us” in the trash.',
            'Took “About us” out of the trash.',
            'Copied “About us” as “About us”.',
            'Deleted “Old news” for good.',
            'Deleted “Archive” for good with the 2 pages under it.',
            'Added a redirect from /antigo to /novo (European Portuguese).',
            'Removed the “gone” answer of /old (British English).',
            'Removed the redirect from /old to /new (British English).',
            'Restored the European Portuguese text of “About us” from revision 2 (now revision 5).',
            'Scheduled “About us”: published at 2026-12-01 09:00 UTC; taken off the site at 2026-12-31 18:00 UTC.',
            'Scheduled “About us”: taken off the site at 2026-12-31 18:00 UTC.',
            'Cleared the schedule of “About us”.',
            'Couldn’t publish “About us” on schedule: the publish checks found errors.',
            'Couldn’t take “About us” off the site on schedule: no permission.',
            'Zoë signed up and confirmed their email address, with the “member” role.',
            'Zoë signed up and confirmed their email address. The sign-up role “editor” isn’t safe for people who sign up, so they got “member” instead.',
            'Changed the site settings: Let anyone create an account, Role of people who sign up, Who can read the site.',
            'Turned on Events.',
            'Turned off Events.',
            'Deleted the data of Events.',
            'Deleted all analytics data.',
            'Used the AI assistant (seo meta) with anthropic, claude-haiku-5-5: 340 tokens.',
            'Asked the AI assistant (connection test) with openai, gpt-5, without a suggestion: invalid key.',
            'Changed the site settings: AI service, AI model, AI service address, AI service key, AI assistant budget, Who may use the AI assistant.',
        ], array_map(static fn (AuditEntry $e): string => $e->summary, $entries));
        self::assertSame(['page.submit', 'page', (string) $page, 3, ['from' => 'draft', 'to' => 'in_review', 'comment' => 'Ready']], [$entries[3]->action, $entries[3]->entityType, $entries[3]->entityId, $entries[3]->actorId, $entries[3]->details]);
        self::assertSame(['settings', null], [$entries[20]->entityType, $entries[20]->entityId]);
        self::assertSame(['menu.moved_up', 'main'], [$entries[14]->action, $entries[14]->entityId]);
        self::assertSame(['page.purged', 'page', '42', ['pages' => [42, 43, 44]]], [$entries[27]->action, $entries[27]->entityType, $entries[27]->entityId, $entries[27]->details]);
        self::assertSame(['redirect.added', 'redirect', null, ['locale' => 'pt-PT', 'from' => 'antigo', 'to' => 'novo']], [$entries[28]->action, $entries[28]->entityType, $entries[28]->entityId, $entries[28]->details]);
        self::assertSame(['page.cloned', ['source' => $page]], [$entries[25]->action, $entries[25]->details]);
        self::assertSame(['page.revision_restored', 4, ['locale' => 'pt-PT', 'restored' => 2, 'revision' => 5]], [$entries[31]->action, $entries[31]->actorId, $entries[31]->details]);
        self::assertSame(['page.scheduled', ['publish_at' => '2026-12-01T09:00:00+00:00', 'unpublish_at' => '2026-12-31T18:00:00+00:00']], [$entries[32]->action, $entries[32]->details]);
        self::assertSame(['page.schedule_failed', null, ['transition' => 'unpublish', 'reason' => 'no permission']], [$entries[36]->action, $entries[36]->actorId, $entries[36]->details]);
        self::assertSame(['user.registered', 'user', (string) $user, $user, ['role' => 'member']], [$entries[37]->action, $entries[37]->entityType, $entries[37]->entityId, $entries[37]->actorId, $entries[37]->details]);
        self::assertSame(['role' => 'member', 'refused_role' => 'editor'], $entries[38]->details);
        self::assertSame(['feature.data_deleted', 'feature', 'acme.events', null, ['module' => 'acme.events']], [$entries[42]->action, $entries[42]->entityType, $entries[42]->entityId, $entries[42]->actorId, $entries[42]->details]);
        self::assertSame(['analytics.deleted', 'settings', null, 1, 'Deleted all analytics data.'], [$entries[43]->action, $entries[43]->entityType, $entries[43]->entityId, $entries[43]->actorId, $entries[43]->summary]);
        self::assertSame(['assistant.used', 'assistant', 'seo_meta', 4, ['task' => 'seo_meta', 'provider' => 'anthropic', 'model' => 'claude-haiku-5-5', 'input_tokens' => 300, 'output_tokens' => 40, 'outcome' => 'ok']], [$entries[44]->action, $entries[44]->entityType, $entries[44]->entityId, $entries[44]->actorId, $entries[44]->details]);
        self::assertCount(22, AuditRecorder::EVENTS);
    }
}
