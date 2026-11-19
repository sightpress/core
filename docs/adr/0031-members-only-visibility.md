# 0031 — Members-only visibility: a site setting, a gate before the routes and "open to everyone" computed once

- Status: Accepted
- Date: 2026-11-18

## Context

U-2 makes a site, or single pages, readable by signed-in members only (plan §1.2 *Visibility*, D24). Pages already
had `is_public` and the voter already let any signed-in account read a published members page, but the site had no
visibility, anonymous visitors got a 404 for a members page, and nothing kept a whole site's sitemap, `llms.txt`,
Markdown and future search away from strangers.

## Decision

- **Site setting** `site.visibility` (`public`, the default, or `members`) in the site's settings, changed on the
  Site settings screen ("Who can read the site") and audited like the other settings. `Site\Contract\SiteVisibility`
  reads it.
- **A gate in the kernel** (`Http\Security\MembersOnlyMiddleware`), right after authentication: on a members-only
  site an anonymous request goes to `/login?return=<path and query>` (302, `no-store`, `noindex, nofollow`) unless
  it is for signing in or up, a code or password page, the site's files (`/assets/`, `/branding/`, the root icons,
  the web manifest) or `robots.txt`. A form sent anonymously goes to `/login` without a way back. It covers every
  route, including ones later modules add (search, analytics), so no module has to remember it.
- **"Open to everyone" is computed in one place**: a page is open to everyone when it is public *and* the site is
  (`SiteVisibility::isOpenToEveryone()`). The page index and page links (sitemap, `llms.txt`, `hreflang`, menus,
  breadcrumbs, structured data), the page's robots rules and the Markdown alternate all use it, so nothing of a
  members-only site, and no members-only page, is listed or indexable. `robots.txt` of a members-only site disallows
  everything and names no sitemap.
- **A members page on a public site** asks an anonymous visitor to sign in and come back (302 to `/login?return=…`)
  instead of the 404 it gave before; its title and content are never sent. Drafts, archived pages and anything else
  a visitor can't open stay 404 (pages in the trash 410, P-1).
- **The way back** is the existing `ReturnTarget`: a path on this site only (no `//host`, backslashes, other schemes
  or the sign-in pages), and never `/admin` without `admin.access`.
- **The editor** has "Who can read this page" (Everyone / Members only) next to the layout; on a members-only site
  its hint says the whole site needs an account anyway.
- The contact form keeps finding its page only among pages public on their own (`PublicPages`); on a members-only
  site the gate already keeps strangers away.

## Consequences

- Per-menu-item visibility and menus that show members pages to signed-in people come with MN-1.
- Search (SR-1) must filter members pages per viewer; the gate already keeps it away from strangers on members-only
  sites.
- Switching a site to members-only purges the cached SEO files (the settings change does) and takes effect on the
  next request.
