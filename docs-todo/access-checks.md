# Access checks: the authorizer, page rules and the admin door (C-3.2)

## What it does

Every "may this person do that?" question goes through one service, the `Authorizer`
(`SightPress\Core\Rbac\Contract\Authorizer`). For plain permissions it checks the person's roles. For pages it
also looks at who wrote the page, its workflow state and whether it is public.

## Who uses it

- **Everyone**, indirectly: it decides what each role sees and can do.
- **Developers**: `$authorizer->can(Actor::fromRequest($request), 'page.edit', $page)`; put
  `AdminAccessMiddleware` on every `/admin` route.

## Page rules

| Action | Who |
| --- | --- |
| Read a published page | everyone if the page is public; anyone signed in (members too) if it is members only |
| Read a page that isn't published (preview) | people who can edit any page; authors with "Edit own pages" for their own pages, except in the trash |
| Edit | "Edit any page" while draft, in review, approved or published; "Edit own pages" only for your own drafts |
| Submit for review | drafts, with "Submit pages for review", by the author or an editor |
| Reject, approve, publish, unpublish | "Publish pages" |
| Archive, restore | "Archive pages" |
| Move to trash, take out of the trash, delete for good | "Delete pages" |

Members never see a page that isn't published.

## The admin panel

Every `/admin` address needs "Use the admin panel" (`admin.access`):

- not signed in → you are sent to the sign-in page (`/login`) and back afterwards;
- signed in without it (every member) → a page "You can't use the admin panel" with links to the home page and to
  *My account*. The admin panel is never linked for these people.

## Code and tests

- `src/Rbac/Contract/` (`Actor`, `Authorizer`, `Voter`, `PageSubject`, `PageState`, `PageAction`,
  `AdminAccessMiddleware`), `src/Rbac/PageVoter.php`, `src/Rbac/VoterAuthorizer.php`,
  `src/Http/Error/NoAdminAccessException.php`
- `tests/Unit/Rbac/PageVoterTest.php` (the full matrix: every role × own/other page × action × state),
  `tests/Integration/Rbac/AdminAccessTest.php`
