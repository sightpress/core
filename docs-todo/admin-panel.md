# The admin panel and the dashboard (C-7.1)

## What it does

Everyone who helps run the site (admins, editors and contributors, and any role with "Use the admin panel")
works in the **admin panel** at `/admin`. Members can't open it: they get a page that explains why, with links to
the site and to their account.

Every admin screen has the same layout:

- a **"Skip to main content"** link first;
- a header with the site name (a link to the site), who is signed in, and a **Sign out** button;
- the **admin navigation** (`<nav>` labelled "Admin"): only the screens you may use are listed, and the screen you
  are on is marked as the current page;
- the screen itself, starting with its heading. Messages such as "Your changes have been saved" are read out by
  screen readers after a change.

The browser tab names the screen, then "Admin" and the site: `Dashboard - Admin - Riverside Library`. When a form
on the screen has problems, the tab starts with how many: `2 errors: Invite someone - Admin - Riverside Library`.

Admin screens are never cached and never shown by search engines (`noindex`).

## The dashboard

The first screen after signing in. It shows:

- **Your drafts**: the pages you started that are still drafts, newest first (for people who can create pages);
- **Waiting for review**: pages sent for review (for people who can publish);
- **Recent activity**: the latest saves of any page, with who made them and when, in your own time zone.

## For module authors

- Add an entry to the navigation with `$registrar->adminMenu()->add(new AdminMenuItem(...))`. It must link to a
  route without placeholders. Give it a permission to show it only to people who hold it.
- Protect every `/admin` route with the `AdminAccessMiddleware` (from the RBAC module's contract).
- Render screens with the admin module's `AdminScreens` service (require the `admin` module): templates extend
  `@admin/layout.html.twig` and fill the `title` and `content` blocks; `content` starts with the `<h1>`. Pass the
  `form` in the context so its errors are counted in the title. `redirect()` sends people on (Post/Redirect/Get)
  with a message for the next screen.
