<?php

declare(strict_types=1);

namespace SightPress\Core\Audit;

use Psr\Clock\ClockInterface;
use SightPress\Core\Infrastructure\Settings\SettingCatalogue;
use SightPress\Core\Media\Contract\MediaChanged;
use SightPress\Core\Media\Contract\MediaDeleted;
use SightPress\Core\Media\Contract\MediaLibrary;
use SightPress\Core\Media\Contract\MediaUploaded;
use SightPress\Core\Menu\Contract\MenuChanged;
use SightPress\Core\Page\Contract\PageCloned;
use SightPress\Core\Page\Contract\PageCreated;
use SightPress\Core\Page\Contract\PageLinks;
use SightPress\Core\Page\Contract\PageMoved;
use SightPress\Core\Page\Contract\PagePurged;
use SightPress\Core\Page\Contract\PageSaved;
use SightPress\Core\Page\Contract\PageScheduled;
use SightPress\Core\Page\Contract\PageTransition;
use SightPress\Core\Page\Contract\PageTransitioned;
use SightPress\Core\Page\Contract\RedirectChanged;
use SightPress\Core\Page\Contract\RevisionRestored;
use SightPress\Core\Page\Contract\ScheduledStepFailed;
use SightPress\Core\Page\Contract\TranslationPublished;
use SightPress\Core\Rbac\Contract\RoleChanged;
use SightPress\Core\Site\Contract\SettingsChanged;
use SightPress\Core\Tenant\LocaleCode;
use SightPress\Core\Tenant\Site;
use SightPress\Core\User\Contract\AccountChanged;
use SightPress\Core\User\Contract\Accounts;

/**
 * Writes an audit log entry for each change to pages (and so their blocks), media, menus, people, roles and site
 * settings, from the events the modules dispatch. Each entry has a sentence a person can read ("Published
 * “About us”.") and the facts as data. It records who and what, never the values of settings, passwords or the text
 * of pages, and no IP addresses.
 */
final readonly class AuditRecorder
{
    /** Events it writes an entry for. */
    public const array EVENTS = [
        PageCreated::class, PageSaved::class, PageMoved::class, PageTransitioned::class, TranslationPublished::class,
        PageCloned::class, PagePurged::class, RedirectChanged::class, RevisionRestored::class,
        PageScheduled::class, ScheduledStepFailed::class,
        MediaUploaded::class, MediaChanged::class, MediaDeleted::class,
        MenuChanged::class, RoleChanged::class, AccountChanged::class, SettingsChanged::class,
    ];

    public function __construct(
        private AuditLog $log,
        private ClockInterface $clock,
        private PageLinks $pages,
        private MediaLibrary $media,
        private Accounts $accounts,
        private Site $site,
        private SettingCatalogue $settings,
    ) {
    }

    public function __invoke(object $event): void
    {
        $entry = match (true) {
            $event instanceof PageCreated => $this->entry($event->authorId, 'page.created', 'page', $event->pageId, sprintf('Started the page %s.', $this->page($event->pageId)), ['locale' => $event->locale]),
            $event instanceof PageSaved => $this->entry($event->savedBy, 'page.saved', 'page', $event->pageId, sprintf('Saved %s (revision %d).', $this->page($event->pageId), $event->revision), ['locale' => $event->locale, 'revision' => $event->revision]),
            $event instanceof PageMoved => $this->entry($event->movedBy, 'page.moved', 'page', $event->pageId, sprintf('Moved %s.', $this->page($event->pageId)), ['from_parent' => $event->fromParentId, 'to_parent' => $event->toParentId, 'position' => $event->position]),
            $event instanceof PageTransitioned => $this->entry($event->actorId, 'page.' . $event->transition->value, 'page', $event->pageId, sprintf(match ($event->transition) {
                PageTransition::Submit => 'Sent %s for review.',
                PageTransition::Reject => 'Sent %s back to draft.',
                PageTransition::Approve => 'Approved %s.',
                PageTransition::Publish => 'Published %s.',
                PageTransition::Unpublish => 'Unpublished %s.',
                PageTransition::Archive => 'Archived %s.',
                PageTransition::Restore => 'Restored %s as a draft.',
                PageTransition::Trash => 'Put %s in the trash.',
                PageTransition::Untrash => 'Took %s out of the trash.',
            }, $this->page($event->pageId)), array_filter(['from' => $event->from->value, 'to' => $event->to->value, 'comment' => $event->comment], static fn (?string $value): bool => $value !== null)),
            $event instanceof TranslationPublished => $this->entry($event->actorId, $event->published ? 'page.translation_published' : 'page.translation_drafted', 'page', $event->pageId, sprintf(
                $event->published ? 'Published the %s translation of %s.' : 'Made the %s translation of %s a draft again.',
                LocaleCode::describe($event->locale),
                $this->page($event->pageId),
            ), ['locale' => $event->locale, 'revision' => $event->revision]),
            $event instanceof PageCloned => $this->entry($event->clonedBy, 'page.cloned', 'page', $event->pageId, sprintf('Copied %s as %s.', $this->page($event->sourceId), $this->page($event->pageId)), ['source' => $event->sourceId]),
            $event instanceof PagePurged => $this->entry($event->purgedBy, 'page.purged', 'page', $event->pageId, sprintf('Deleted “%s” for good%s.', $event->title, count($event->pageIds) > 1 ? sprintf(' with the %d pages under it', count($event->pageIds) - 1) : ''), ['pages' => $event->pageIds]),
            $event instanceof RevisionRestored => $this->entry($event->restoredBy, 'page.revision_restored', 'page', $event->pageId, sprintf('Restored the %s text of %s from revision %d (now revision %d).', LocaleCode::describe($event->locale), $this->page($event->pageId), $event->restored, $event->revision), ['locale' => $event->locale, 'restored' => $event->restored, 'revision' => $event->revision]),
            $event instanceof PageScheduled => $this->entry($event->actorId, 'page.scheduled', 'page', $event->pageId, $event->publishAt === null && $event->unpublishAt === null
                ? sprintf('Cleared the schedule of %s.', $this->page($event->pageId))
                : sprintf('Scheduled %s: %s.', $this->page($event->pageId), implode('; ', array_filter([
                    $event->publishAt === null ? null : 'published at ' . $event->publishAt->format('Y-m-d H:i') . ' UTC',
                    $event->unpublishAt === null ? null : 'taken off the site at ' . $event->unpublishAt->format('Y-m-d H:i') . ' UTC',
                ]))), ['publish_at' => $event->publishAt?->format(DATE_ATOM), 'unpublish_at' => $event->unpublishAt?->format(DATE_ATOM)]),
            $event instanceof ScheduledStepFailed => $this->entry($event->scheduledBy, 'page.schedule_failed', 'page', $event->pageId, sprintf(
                $event->transition === PageTransition::Publish ? 'Couldn’t publish %s on schedule: %s.' : 'Couldn’t take %s off the site on schedule: %s.',
                $this->page($event->pageId),
                $event->reason,
            ), ['transition' => $event->transition->value, 'reason' => $event->reason]),
            $event instanceof RedirectChanged => $this->entry($event->actorId, 'redirect.' . $event->change, 'redirect', null, match (true) {
                $event->change === RedirectChanged::ADDED => sprintf('Added a redirect from /%s to /%s (%s).', $event->from, (string) $event->to, LocaleCode::describe($event->locale)),
                $event->to === null => sprintf('Removed the “gone” answer of /%s (%s).', $event->from, LocaleCode::describe($event->locale)),
                default => sprintf('Removed the redirect from /%s to /%s (%s).', $event->from, $event->to, LocaleCode::describe($event->locale)),
            }, ['locale' => $event->locale, 'from' => $event->from, 'to' => $event->to]),
            $event instanceof MediaUploaded => $this->entry($event->uploadedBy, 'media.uploaded', 'media', $event->mediaId, sprintf('Uploaded %s.', $this->file($event->mediaId)), ['kind' => $event->kind->value]),
            $event instanceof MediaChanged => $this->entry($event->changedBy, 'media.changed', 'media', $event->mediaId, sprintf('Changed the %s of %s.', self::words($event->fields), $this->file($event->mediaId)), ['fields' => $event->fields]),
            $event instanceof MediaDeleted => $this->entry($event->deletedBy, 'media.deleted', 'media', $event->mediaId, sprintf('Deleted “%s”.', $event->originalName), []),
            $event instanceof MenuChanged => $this->entry($event->actorId, 'menu.' . $event->change, 'menu', $event->menu->value, sprintf('%s: %s %s.', $event->menu->label(), str_replace('_', ' ', ucfirst($event->change)), $event->pageId === null ? sprintf('the link to %s', $event->url ?? 'an address') : sprintf('the entry for %s', $this->page($event->pageId))), array_filter(['item' => $event->itemId, 'page' => $event->pageId, 'url' => $event->url], static fn (mixed $value): bool => $value !== null)),
            $event instanceof RoleChanged => $this->entry($event->changedBy, 'user.role_changed', 'user', $event->userId, sprintf('Changed the role of %s from %s to %s.', $this->person($event->userId), $event->from ?? 'none', $event->to), ['from' => $event->from, 'to' => $event->to]),
            $event instanceof AccountChanged => $this->entry($event->changedBy, 'user.changed', 'user', $event->userId, sprintf('Changed the %s of %s.', self::words($event->fields), $this->person($event->userId)), ['fields' => $event->fields]),
            $event instanceof SettingsChanged => $this->entry($event->actorId, 'settings.changed', 'settings', null, sprintf('Changed the site settings: %s.', implode(', ', array_map($this->setting(...), $event->keys))), ['keys' => $event->keys]),
            default => null,
        };
        if ($entry !== null) {
            $this->log->append($entry);
        }
    }

    /**
     * @param array<string, mixed> $details
     */
    private function entry(?int $actorId, string $action, string $type, int|string|null $id, string $summary, array $details): AuditEntry
    {
        return new AuditEntry($this->clock->now(), $actorId, $action, $type, $id === null ? null : (string) $id, $summary, $details);
    }

    private function setting(string $key): string
    {
        $setting = $this->settings->get($key);
        if ($setting !== null) {
            return $setting->label;
        }

        return match ($key) {
            'site.name' => 'Site name',
            'site.default_locale' => 'Main language',
            'site.locales' => 'Languages',
            'site.default_timezone' => 'Time zone',
            'branding.logo' => 'Logo',
            'branding.icon' => 'Icon',
            'branding.share_image' => 'Share picture',
            default => $key,
        };
    }

    private function page(int $id): string
    {
        $link = $this->pages->find([$id], $this->site->defaultLocale)[$id] ?? null;

        return $link === null ? sprintf('page %d', $id) : sprintf('“%s”', $link->title);
    }

    private function file(int $id): string
    {
        $file = $this->media->file($id);

        return $file === null ? sprintf('file %d', $id) : sprintf('“%s”', $file->originalName());
    }

    private function person(int $id): string
    {
        return $this->accounts->findAccount($id)?->effectiveName() ?? sprintf('account %d', $id);
    }

    /**
     * `alt`, `display_name` → "alt, display name".
     *
     * @param list<string> $fields
     */
    private static function words(array $fields): string
    {
        return $fields === [] ? 'details' : implode(', ', array_map(static fn (string $field): string => str_replace('_', ' ', $field), $fields));
    }
}
