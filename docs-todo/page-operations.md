# Page operations and redirects (P-1)

Pages can be copied, archived, put in the trash, deleted for good and moved around the site from the page editor.
Old addresses keep working. ADR-0027.

## Who and what

- **Copy this page** (anyone who can start pages, `page.create`): a new draft right after the page, in every
  language it is written in, titled "Copy of …" with `-copy` added to its address (`-copy-2` when that is taken).
  The pages under it aren't copied. The copy opens in the editor.
- **Archive** / **Restore as a draft** (`page.archive`, editors and admins): archiving takes a page off the site and
  keeps it; visitors get "not found". Restoring makes it a draft again.
- **Put in the trash** / **Take out of the trash** (`page.delete`, editors and admins): a page in the trash tells
  visitors it is gone (410). A page goes in the trash only when the pages under it are there already; it comes out
  as a draft, and only when the page above it isn't in the trash. Find pages in the trash with the *State* filter of
  the page list.
- **Delete this page for good** (a page in the trash, `page.delete`): a confirmation page says what goes with it (the
  pages under it, every translation and older version). Its address then tells visitors and search engines the
  page is gone, until a new page uses it.
- **Place in the site** (`page.edit.any`): *Move up*, *Move down*, *Move under the page before it*, *Move out to
  the level above*, and *Move to* (the top level or under any other page). Pages under it move with it.

## Redirects

- When a published page's address changes (it moved, a page above it moved, or it got a new address), the old
  address leads to the new one (301), in that language. Moving it again updates the old redirects, so a redirect is
  always one hop. A page that takes an old address wins over its redirect.
- **Redirects** in the admin menu (`/admin/redirects`, `page.edit.any`): every old address, where it leads (or
  "Gone: the page was deleted"), with a *Remove* button; and a form to add one (an old address no page uses → an
  address in the same language). Loops are refused.
- The Markdown alternate of an old address (`/old.md`) leads to the new one's.

Every step is in the audit log ("Archived “News”.", "Copied “About” as “Copy of About”.", "Deleted “Old news” for
good.", "Added a redirect from /old to /new (British English).").
