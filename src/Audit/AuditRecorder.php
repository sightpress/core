<?php

declare(strict_types=1);

namespace SightPress\Core\Audit;

use Psr\Clock\ClockInterface;
use SightPress\Core\Infrastructure\Settings\SettingCatalogue;
use SightPress\Module\Event\AnalyticsDataDeleted;
use SightPress\Module\Event\AssistantUsed;
use SightPress\Module\Event\ThemeApplied;
use SightPress\Module\Event\ThemeColoursChanged;
use SightPress\Module\Event\MediaChanged;
use SightPress\Module\Event\MediaDeleted;
use SightPress\Core\Media\Contract\MediaLibrary;
use SightPress\Core\Menu\Contract\MenuName;
use SightPress\Module\Event\MediaUploaded;
use SightPress\Module\Event\MenuChanged;
use SightPress\Module\Event\PageCloned;
use SightPress\Module\Event\AccountRegistered;
use SightPress\Module\Event\PageCreated;
use SightPress\Core\Page\Contract\PageLinks;
use SightPress\Module\Event\PageMoved;
use SightPress\Module\Event\PagePurged;
use SightPress\Module\Event\PageSaved;
use SightPress\Module\Event\PageScheduled;
use SightPress\Core\Page\Contract\PageTransition;
use SightPress\Module\Event\PageTransitioned;
use SightPress\Module\Event\RedirectChanged;
use SightPress\Module\Event\RevisionRestored;
use SightPress\Module\Event\ScheduledStepFailed;
use SightPress\Module\Event\TranslationPublished;
use SightPress\Module\Event\RoleChanged;
use SightPress\Core\Auth\Contract\SignUp;
use SightPress\Core\Site\Contract\SiteSettings;
use SightPress\Core\Auth\Contract\StepUpChecked;
use SightPress\Core\Sms\Contract\TextMessageOptChanged;
use SightPress\Core\Sms\Contract\TextMessageTested;
use SightPress\Core\Rbac\Contract\RoleEdited;
use SightPress\Module\Event\SettingsChanged;
use SightPress\Core\Tenant\LocaleCode;
use SightPress\Core\Tenant\Site;
use SightPress\Module\Event\SiteModuleChanged;
use SightPress\Module\Event\AccountChanged;
use SightPress\Core\User\Contract\Accounts;

/**
 * Writes an audit log entry for each change to pages (and so their blocks), media, menus, people, roles, site
 * settings and features, from the events the modules dispatch. Each entry has a sentence a person can read ("Published
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
        MenuChanged::class, RoleChanged::class, RoleEdited::class, AccountChanged::class, AccountRegistered::class, SettingsChanged::class,
        SiteModuleChanged::class, AnalyticsDataDeleted::class, AssistantUsed::class, ThemeApplied::class, ThemeColoursChanged::class,
        TextMessageTested::class, TextMessageOptChanged::class, StepUpChecked::class,
    ];

    public function __construct(
        private AuditLog $log,
        private ClockInterface $clock,
        private PageLinks $pages,
        private MediaLibrary $media,
        private Accounts $accounts,
        private Site $site,
        private SettingCatalogue $settings,
        private SiteSettings $siteSettings,
    ) {
    }

    public function __invoke(object $event): void
    {
        $entry = match (true) {
            $event instanceof PageCreated => $this->entry($event->authorId, 'page.created', 'page', $event->pageId, sprintf('Started the page %s.', $this->page($event->pageId)), ['locale' => $event->locale]),
            $event instanceof PageSaved => $this->entry($event->savedBy, 'page.saved', 'page', $event->pageId, sprintf('Saved %s (revision %d).', $this->page($event->pageId), $event->revision), ['locale' => $event->locale, 'revision' => $event->revision]),
            $event instanceof PageMoved => $this->entry($event->movedBy, 'page.moved', 'page', $event->pageId, sprintf('Moved %s.', $this->page($event->pageId)), ['from_parent' => $event->fromParentId, 'to_parent' => $event->toParentId, 'position' => $event->position]),
            $event instanceof PageTransitioned => $this->entry($event->actorId, 'page.' . $event->transition, 'page', $event->pageId, sprintf(match (PageTransition::from($event->transition)) {
                PageTransition::Submit => 'Sent %s for review.',
                PageTransition::Reject => 'Sent %s back to draft.',
                PageTransition::Approve => 'Approved %s.',
                PageTransition::Publish => 'Published %s.',
                PageTransition::Unpublish => 'Unpublished %s.',
                PageTransition::Archive => 'Archived %s.',
                PageTransition::Restore => 'Restored %s as a draft.',
                PageTransition::Trash => 'Put %s in the trash.',
                PageTransition::Untrash => 'Took %s out of the trash.',
            }, $this->page($event->pageId)), array_filter(['from' => $event->from, 'to' => $event->to, 'comment' => $event->comment], static fn (?string $value): bool => $value !== null)),
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
                $event->transition === PageTransition::Publish->value ? 'Couldn’t publish %s on schedule: %s.' : 'Couldn’t take %s off the site on schedule: %s.',
                $this->page($event->pageId),
                $event->reason,
            ), ['transition' => $event->transition, 'reason' => $event->reason]),
            $event instanceof RedirectChanged => $this->entry($event->actorId, 'redirect.' . $event->change, 'redirect', null, match (true) {
                $event->change === RedirectChanged::ADDED => sprintf('Added a redirect from /%s to /%s (%s).', $event->from, (string) $event->to, LocaleCode::describe($event->locale)),
                $event->to === null => sprintf('Removed the “gone” answer of /%s (%s).', $event->from, LocaleCode::describe($event->locale)),
                default => sprintf('Removed the redirect from /%s to /%s (%s).', $event->from, $event->to, LocaleCode::describe($event->locale)),
            }, ['locale' => $event->locale, 'from' => $event->from, 'to' => $event->to]),
            $event instanceof MediaUploaded => $this->entry($event->uploadedBy, 'media.uploaded', 'media', $event->mediaId, sprintf('Uploaded %s.', $this->file($event->mediaId)), ['kind' => $event->kind]),
            $event instanceof MediaChanged => $this->entry($event->changedBy, 'media.changed', 'media', $event->mediaId, sprintf('Changed the %s of %s.', self::words($event->fields), $this->file($event->mediaId)), ['fields' => $event->fields]),
            $event instanceof MediaDeleted => $this->entry($event->deletedBy, 'media.deleted', 'media', $event->mediaId, sprintf('Deleted “%s”.', $event->originalName), []),
            $event instanceof MenuChanged => $this->entry($event->actorId, 'menu.' . $event->change, 'menu', $event->menu, sprintf('%s: %s %s.', MenuName::tryFrom($event->menu)?->label() ?? ucfirst($event->menu), str_replace('_', ' ', ucfirst($event->change)), $event->pageId === null ? sprintf('the link to %s', $event->url ?? 'an address') : sprintf('the entry for %s', $this->page($event->pageId))), array_filter(['item' => $event->itemId, 'page' => $event->pageId, 'url' => $event->url], static fn (mixed $value): bool => $value !== null)),
            $event instanceof RoleChanged => $this->entry($event->changedBy, 'user.role_changed', 'user', $event->userId, sprintf('Changed the role of %s from %s to %s.', $this->person($event->userId), $event->from ?? 'none', $event->to), ['from' => $event->from, 'to' => $event->to]),
            $event instanceof RoleEdited => $this->entry($event->actorId, 'role.' . $event->change, 'role', $event->role, match ($event->change) {
                RoleEdited::CREATED => sprintf('Created the role “%s”%s.', $event->label, $event->added === [] ? '' : ' with ' . implode(', ', $event->added)),
                RoleEdited::RENAMED => sprintf('Renamed the role “%s” to “%s”.', $event->from, $event->label),
                RoleEdited::DELETED => sprintf('Deleted the role “%s”.', $event->label),
                default => sprintf('Changed what the role “%s” may do: %s.', $event->label, implode('; ', array_filter([
                    $event->added === [] ? null : 'gave ' . implode(', ', $event->added),
                    $event->removed === [] ? null : 'took away ' . implode(', ', $event->removed),
                ]))),
            }, array_filter(['label' => $event->label, 'added' => $event->added, 'removed' => $event->removed, 'from' => $event->from], static fn (array|string|null $value): bool => $value !== null && $value !== [])),
            $event instanceof AccountChanged => $this->entry($event->changedBy, 'user.changed', 'user', $event->userId, sprintf('Changed the %s of %s.', self::words($event->fields), $this->person($event->userId)), ['fields' => $event->fields]),
            $event instanceof AccountRegistered => $this->registered($event),
            $event instanceof SiteModuleChanged => $this->entry($event->actorId, 'feature.' . $event->change, 'feature', $event->moduleId, sprintf(match ($event->change) {
                SiteModuleChanged::ENABLED => 'Turned on %s.',
                SiteModuleChanged::DISABLED => 'Turned off %s.',
                SiteModuleChanged::DATA_DELETED => 'Deleted the data of %s.',
            }, $event->name), ['module' => $event->moduleId]),
            $event instanceof ThemeApplied => $this->entry($event->actorId, 'theme.applied', 'theme', $event->themeId, sprintf('Applied the theme %s.', $event->name), ['previous' => $event->previous]),
            $event instanceof ThemeColoursChanged => $this->entry($event->actorId, $event->colours === [] ? 'theme.colours_reset' : 'theme.colours_changed', 'theme', $event->themeId, $event->colours === []
                ? sprintf('Went back to the colours of the theme %s.', $event->name)
                : sprintf('Changed the colours of the theme %s.', $event->name), ['colours' => $event->colours]),
            $event instanceof AnalyticsDataDeleted => $this->entry($event->actorId, 'analytics.deleted', 'settings', null, 'Deleted all analytics data.', []),
            $event instanceof AssistantUsed => $this->entry($event->actorId, 'assistant.used', 'assistant', $event->task, sprintf(
                $event->outcome === 'ok' ? 'Used the AI assistant (%s) with %s, %s: %d tokens.' : 'Asked the AI assistant (%s) with %s, %s, without a suggestion: %s.',
                str_replace('_', ' ', $event->task),
                $event->provider,
                $event->model,
                ...($event->outcome === 'ok' ? [$event->inputTokens + $event->outputTokens] : [str_replace('_', ' ', $event->outcome)]),
            ), ['task' => $event->task, 'provider' => $event->provider, 'model' => $event->model, 'input_tokens' => $event->inputTokens, 'output_tokens' => $event->outputTokens, 'outcome' => $event->outcome]),
            $event instanceof StepUpChecked => $this->entry($event->actorId, $event->confirmed ? 'security.confirmed' : 'security.confirm_failed', 'user', $event->actorId, sprintf(
                $event->confirmed ? '%s confirmed it was them with a code sent to %s.' : '%s typed a wrong confirmation code (sent to %s).',
                $this->person($event->actorId),
                $event->maskedNumber,
            ), ['number' => $event->maskedNumber]),
            $event instanceof TextMessageTested => $this->entry($event->actorId, 'sms.tested', 'sms', null, $event->sent
                ? sprintf('Sent a test text message to %s with %s.', $event->maskedNumber, $event->provider)
                : sprintf('Tried to send a test text message to %s with %s: %s.', $event->maskedNumber, $event->provider, str_replace('_', ' ', (string) $event->failure)), array_filter(['to' => $event->maskedNumber, 'provider' => $event->provider, 'failure' => $event->failure], static fn (?string $value): bool => $value !== null)),
            $event instanceof TextMessageOptChanged => $this->entry(null, $event->stopped ? 'sms.opted_out' : 'sms.opted_in', 'sms', null, sprintf(
                $event->stopped ? '%s replied STOP: it gets no more text notifications.' : '%s replied START: it gets text notifications again.',
                $event->maskedNumber,
            ), ['number' => $event->maskedNumber]),
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

    /**
     * Someone signed up. While the site's sign-up is off, only domain registration lets people in (X-7), so the
     * entry says the account came from the organisation's domains (source `domain`); otherwise from sign-up (`self`).
     */
    private function registered(AccountRegistered $event): AuditEntry
    {
        $domain = !SignUp::isOpen($this->siteSettings->get(SignUp::SETTING));
        $how = $domain ? 'registered with their organisation email address and confirmed it' : 'signed up and confirmed their email address';

        return $this->entry($event->userId, 'user.registered', 'user', $event->userId, $event->refusedRole === null
            ? sprintf('%s %s, with the “%s” role.', $this->person($event->userId), $how, $event->role)
            : sprintf('%s %s. The sign-up role “%s” isn’t safe for people who sign up, so they got “%s” instead.', $this->person($event->userId), $how, $event->refusedRole, $event->role), array_filter(['role' => $event->role, 'refused_role' => $event->refusedRole, 'source' => $domain ? 'domain' : 'self'], static fn (?string $value): bool => $value !== null));
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
            'site.visibility' => 'Who can read the site',
            'people.registration' => 'Let anyone create an account',
            'rbac.registration_role' => 'Role of people who sign up',
            'people.domain_registration' => 'Registration with an organisation email address',
            'branding.logo' => 'Logo',
            'branding.icon' => 'Icon',
            'branding.share_image' => 'Share picture',
            'media.limits' => 'Upload limits',
            'storage.provisioned' => 'Cloud storage set up',
            'analytics.retention_months' => 'How long analytics are kept',
            'assistant.provider' => 'AI service',
            'assistant.model' => 'AI model',
            'assistant.base_url' => 'AI service address',
            'assistant.key' => 'AI service key',
            'assistant.monthly_budget' => 'AI assistant budget',
            'assistant.roles' => 'Who may use the AI assistant',
            'sms.provider' => 'Text message provider',
            'sms.account' => 'Text message account',
            'sms.key' => 'Text message provider key',
            'sms.sender' => 'Text message sender',
            'sms.region' => 'Text message provider region',
            'sms.monthly_budget' => 'Text message budget',
            'security.two_step' => 'Two-step confirmation',
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
