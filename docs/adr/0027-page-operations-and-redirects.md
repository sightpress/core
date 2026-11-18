# 0027 — Page operations: archive and trash steps, a redirects table and "gone" addresses

- Status: Proposed
- Date: 2026-11-18

## Context

ADR-0006 named the `archive`, `restore`, `trash` and `untrash` steps and said trashed pages answer 410, but M1 only
built the review and publishing steps. P-1 adds them, together with copying pages, moving them around the tree from
the admin and keeping old addresses working. Several details were open: a step from several states, what happens to
the pages under a page in the trash, how old addresses are stored so a redirect is always one hop, and what an
address answers once its page is deleted for good.

## Decision

- `Page\Contract\PageTransition` gets `Archive`, `Restore`, `Trash` and `Untrash`. `fromState()` becomes
  `fromStates(): list<PageState>` (archive starts from any live state, trash from any state but the trash), and
  `isBackwards()` says which steps take a page off the site. This changes a contract: no module outside core uses
  it yet.
- The tree stays whole: a page goes in the trash only when every page under it is there already, comes out only when
  the page above it isn't in the trash, and nothing is moved under a page in the trash. Deleting for good ("purge",
  not a state) takes a page in the trash with every page under it (all in the trash), their translations and
  revisions, and closes up the positions of the pages after it.
- A `page_redirects` table (per locale: old path → new path, or no target for "gone"), kept by
  `Page\PageRedirects`:
  - when a translation's address changes (it moved, or a page above it did, or it got a new slug) and visitors could
    read it there, the old address leads to the new one; redirects that led to the old address are pointed at the
    new one, so every redirect is one hop and moves can't make loops;
  - an address a page takes loses its redirect: the page wins;
  - deleting a page for good leaves its addresses, and the redirects that led to them, as "gone";
  - people add redirects of their own on `/admin/redirects` (`page.edit.any`): never from an address a page uses,
    the target is followed to its end, and a loop is refused. Every redirect can be removed.
- Public pages: an address with no page that has a redirect answers 301 (the Markdown alternate leads to the new
  Markdown address); "gone" and pages in the trash answer 410; archived pages 404 like drafts.
- Copying a page makes a new draft right after it, in every site language it is written in: "Copy of …" in that
  language, the slug with `-copy` (`-copy-2` …). The pages under it are not copied.
- New events: `PageCloned`, `PagePurged` and `RedirectChanged` (for the audit log; sitemap and `llms.txt` caches are
  forgotten on `PagePurged` and every step). IndexNow pings on any step to or from `published`.

## Consequences

- Links and bookmarks survive moves and renames without anyone thinking about them; the table only grows with
  addresses that were public.
- A page in the trash keeps its address (so it can come back), so a new page can't use it until the old one is
  deleted for good.
- The home page can still be archived or trashed; a later task may stop that or ask for another home page first.
