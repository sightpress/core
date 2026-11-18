<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Contract;

use SightPress\Core\Rbac\Contract\PageAction;
use SightPress\Core\Rbac\Contract\PageState;

/**
 * The workflow steps of a page (ADR-0006, ADR-0027): a draft is submitted for review, rejected back to draft or
 * approved, an approved page is published, and a published page can be taken back to draft. Any live page can be
 * archived (taken off the site and kept) and restored as a draft; any page can be put in the trash and taken out of
 * it as a draft. Deleting a page in the trash for good is not a step: the page is gone.
 */
enum PageTransition: string
{
    case Submit = 'submit';
    case Reject = 'reject';
    case Approve = 'approve';
    case Publish = 'publish';
    case Unpublish = 'unpublish';
    case Archive = 'archive';
    case Restore = 'restore';
    case Trash = 'trash';
    case Untrash = 'untrash';

    /**
     * The states the step starts from.
     *
     * @return list<PageState>
     */
    public function fromStates(): array
    {
        return match ($this) {
            self::Submit => [PageState::Draft],
            self::Reject, self::Approve => [PageState::InReview],
            self::Publish => [PageState::Approved],
            self::Unpublish => [PageState::Published],
            self::Archive => [PageState::Draft, PageState::InReview, PageState::Approved, PageState::Published],
            self::Restore => [PageState::Archived],
            self::Trash => [PageState::Draft, PageState::InReview, PageState::Approved, PageState::Published, PageState::Archived],
            self::Untrash => [PageState::Trash],
        };
    }

    public function toState(): PageState
    {
        return match ($this) {
            self::Submit => PageState::InReview,
            self::Reject, self::Unpublish, self::Restore, self::Untrash => PageState::Draft,
            self::Approve => PageState::Approved,
            self::Publish => PageState::Published,
            self::Archive => PageState::Archived,
            self::Trash => PageState::Trash,
        };
    }

    /**
     * The permission check (page voter) that guards the step.
     */
    public function action(): PageAction
    {
        return match ($this) {
            self::Submit => PageAction::Submit,
            self::Reject => PageAction::Reject,
            self::Approve => PageAction::Approve,
            self::Publish => PageAction::Publish,
            self::Unpublish => PageAction::Unpublish,
            self::Archive => PageAction::Archive,
            self::Restore => PageAction::Restore,
            self::Trash => PageAction::Trash,
            self::Untrash => PageAction::Untrash,
        };
    }

    /**
     * What the step does, for buttons and messages: "Submit for review", "Publish".
     */
    public function label(): string
    {
        return match ($this) {
            self::Submit => 'Submit for review',
            self::Reject => 'Send back to draft',
            self::Approve => 'Approve',
            self::Publish => 'Publish',
            self::Unpublish => 'Unpublish',
            self::Archive => 'Archive',
            self::Restore => 'Restore as a draft',
            self::Trash => 'Put in the trash',
            self::Untrash => 'Take out of the trash',
        };
    }

    /**
     * Whether the step takes the page off the site or out of use (shown as a secondary or danger button).
     */
    public function isBackwards(): bool
    {
        return in_array($this, [self::Reject, self::Unpublish, self::Archive, self::Trash], true);
    }
}
