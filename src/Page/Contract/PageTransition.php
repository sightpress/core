<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Contract;

use SightPress\Core\Rbac\Contract\PageAction;
use SightPress\Core\Rbac\Contract\PageState;

/**
 * The workflow steps of a page in this release (ADR-0006): a draft is submitted for review, rejected back to draft or
 * approved, an approved page is published, and a published page can be taken back to draft. Archive, restore and the
 * trash come later.
 */
enum PageTransition: string
{
    case Submit = 'submit';
    case Reject = 'reject';
    case Approve = 'approve';
    case Publish = 'publish';
    case Unpublish = 'unpublish';

    public function fromState(): PageState
    {
        return match ($this) {
            self::Submit => PageState::Draft,
            self::Reject, self::Approve => PageState::InReview,
            self::Publish => PageState::Approved,
            self::Unpublish => PageState::Published,
        };
    }

    public function toState(): PageState
    {
        return match ($this) {
            self::Submit => PageState::InReview,
            self::Reject, self::Unpublish => PageState::Draft,
            self::Approve => PageState::Approved,
            self::Publish => PageState::Published,
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
        };
    }
}
