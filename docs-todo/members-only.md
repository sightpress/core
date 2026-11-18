# Members-only sites and pages (U-2)

A whole site, or single pages, can be for members only: people with an account on the site who are signed in.
ADR-0031.

## A members-only site

**Site settings → Who can read the site**: *Everyone* (the default) or *Only people with an account, once they sign
in*.

On a members-only site:

- Anyone who isn't signed in and opens a page is taken to **Sign in**, which says "This site is for its members.
  Sign in to read it.", and comes back to that page afterwards.
- They can still reach signing in, creating an account (when the site allows it), the code pages, "Forgot your
  password?", accepting an invitation and `robots.txt`.
- Search engines and AI services can't read anything: `robots.txt` asks them to stay away, the sitemap and
  `llms.txt` list nothing, and every page says `noindex, nofollow`.
- Every signed-in member, whatever their role, reads every published page.

## A members-only page on a public site

In the page editor, **About the page → Who can read this page**: *Everyone* or *Members only*.

A members-only page asks visitors who aren't signed in to sign in, then shows it. It is left out of the sitemap,
`llms.txt`, the language alternates and search engines (`noindex, nofollow`); its Markdown version too.

## Never for members

Drafts, older versions, archived pages and pages in the trash are only for the people who edit pages; members get
"not found" (or "gone" for the trash), and the admin panel answers "forbidden" with no admin link shown.
