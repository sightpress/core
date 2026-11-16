# Page workflow: draft, review, approval, publishing (C-4.4)

## What it does

Every page goes through the same steps before visitors can see it:

| Step | From | To | Who can do it |
| --- | --- | --- | --- |
| **Submit for review** | Draft | Waiting for review | The page's author (with "Submit pages for review"), or anyone who can edit any page |
| **Send back to draft** | Waiting for review | Draft | Editors and admins ("Publish pages") |
| **Approve** | Waiting for review | Approved | Editors and admins |
| **Publish** | Approved | Published | Editors and admins |
| **Unpublish** | Published | Draft | Editors and admins |

- Only **published** pages are on the public site. Unpublishing takes a page off the site straight away and makes
  it a draft again.
- Editors go through review and approval for their own pages too.
- When someone submits a page, **everyone who can publish** (editors and admins, except the person who submitted it)
  gets an email with a link to the page. Accounts that are switched off, or haven't chosen a password yet, get no
  email.
- Sending a page back or unpublishing it can come with a comment (up to 1,000 characters), for example what to
  change.
- A step that doesn't fit the page's state is refused with a clear message, for example *"Publish" can't be done
  now: the page is a draft.* A step the person may not take is refused as not allowed.

Archive, restore and the trash come in a later release.

## For developers

- `PageWorkflow::available($page, $actor)`, `can()` and `apply($page, PageTransition::Publish, $actor, $comment)`
  (service in the `page` module). The steps are `Page\Contract\PageTransition`. The page voter's checks guard each
  step, and `WorkflowException` has `forbidden` set when the person may not take it (answer 403).
- After each step, `Page\Contract\PageTransitioned` (page id, step, from, to, actor, comment) is dispatched. The
  review email (`ReviewRequestMailer`) listens to it, as will the audit log (C-7.4) and the sitemap.
- Each site container now has its own event dispatcher (ADR-0016), so listeners of page events can use the site's
  database.
- New contracts: `Rbac\Contract\PermissionHolders` (who holds a permission) and `User\Contract\AccountDirectory`
  (several accounts in one query).

## Still to write in the docs

- How-to (non-developers): submit a page for review; review, approve and publish a page; take a page off the site.
- Reference: the states and steps, and which role can take which step.
