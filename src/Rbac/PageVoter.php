<?php

declare(strict_types=1);

namespace SightPress\Core\Rbac;

use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Rbac\Contract\PageAction;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Rbac\Contract\PageSubject;
use SightPress\Core\Rbac\Contract\Voter;

/**
 * Who may do what to a page: permissions plus ownership, the page's state and its visibility.
 *
 * - **View**: a published page is open to everyone when it is public, and to any signed-in account (a `member`
 *   included) when it is members only. Anything that isn't published is admin-only: people who can edit any page
 *   see it in every state, authors with `page.edit.own` see their own pages except in the trash. A `member` never
 *   sees an unpublished page (D24).
 * - **Edit**: `page.edit.any` while the page is a draft, in review, approved or published; `page.edit.own` only
 *   for the author's own drafts (once submitted, a contributor waits for the review).
 * - **Submit**: drafts, with `page.submit`, by the author or anyone who can edit any page.
 * - **Reject, approve**: pages in review; **publish**: approved pages; **unpublish**: published pages — all with
 *   `page.publish`.
 * - **Archive**: any live state, **restore**: archived pages — with `page.archive`.
 * - **Trash**: any state but the trash; **untrash** and **purge**: pages in the trash — with `page.delete`.
 */
final readonly class PageVoter implements Voter
{
    public function supports(string $action, object $subject): bool
    {
        return $subject instanceof PageSubject && PageAction::tryFrom($action) !== null;
    }

    public function vote(Actor $actor, string $action, object $subject): bool
    {
        $action = PageAction::tryFrom($action);
        if ($action === null || !$subject instanceof PageSubject) {
            return false;
        }

        $state = $subject->state();
        $isLive = in_array(
            $state,
            [PageState::Draft, PageState::InReview, PageState::Approved, PageState::Published],
            true,
        );

        return match ($action) {
            PageAction::View => $this->canView($actor, $subject),
            PageAction::Edit => $this->canEdit($actor, $subject),
            PageAction::Submit => $state === PageState::Draft
                && $actor->has('page.submit')
                && ($actor->is($subject->authorId()) || $actor->has('page.edit.any')),
            PageAction::Reject, PageAction::Approve => $state === PageState::InReview && $actor->has('page.publish'),
            PageAction::Publish => $state === PageState::Approved && $actor->has('page.publish'),
            PageAction::Unpublish => $state === PageState::Published && $actor->has('page.publish'),
            PageAction::Archive => $isLive && $actor->has('page.archive'),
            PageAction::Restore => $state === PageState::Archived && $actor->has('page.archive'),
            PageAction::Trash => $state !== PageState::Trash && $actor->has('page.delete'),
            PageAction::Untrash, PageAction::Purge => $state === PageState::Trash && $actor->has('page.delete'),
        };
    }

    private function canView(Actor $actor, PageSubject $page): bool
    {
        if ($page->state() === PageState::Published) {
            return $page->isPublic() || $actor->isSignedIn();
        }
        if ($actor->has('page.edit.any')) {
            return true;
        }

        return $page->state() !== PageState::Trash
            && $actor->has('page.edit.own')
            && $actor->is($page->authorId());
    }

    private function canEdit(Actor $actor, PageSubject $page): bool
    {
        $state = $page->state();
        if ($actor->has('page.edit.any')) {
            return in_array(
                $state,
                [PageState::Draft, PageState::InReview, PageState::Approved, PageState::Published],
                true,
            );
        }

        return $state === PageState::Draft && $actor->has('page.edit.own') && $actor->is($page->authorId());
    }
}
