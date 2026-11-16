# Starter content (C-6.4)

## What it does

Every site made with `site:create` starts with six published pages in its main language, so it is a working,
accessible website from the first minute:

| Page | Address | What's on it |
| --- | --- | --- |
| Welcome to *site name* | `/` (the home page, `home` layout) | A short welcome and links to About, Contact and the accessibility statement |
| About us / About me | `/about` | Placeholder text that says what to write there |
| Contact us / Contact me | `/contact` | A few words and a contact form whose messages go to the first admin |
| Privacy notice | `/privacy` | What the website collects (contact form messages, server logs) and people's rights |
| Terms of use | `/terms` | Short terms for using the website |
| Accessibility statement | `/accessibility` | WCAG 2.2 AA aim, what visitors can expect, how to report a problem |

- The wording depends on who runs the site (`--owner-type`): an **organization** writes "we" and "us", a
  **person** writes "I" and "me" and uses the first admin's name.
- **Menus**: About and Contact go in the main menu; Privacy, Terms and the accessibility statement in the footer
  menu.
- Every page has a description for search engines, and passes the publish checks without errors.
- The pages are written in English for now, whatever the site's language; translate or rewrite them in the admin.
- The first admin is the author of every page. The contact form's address is never shown on the page.

`site:create` says what it did, for example:

```text
Published the starter pages: Welcome to Riverside Library, About us, Contact us, Privacy notice, Terms of use, Accessibility statement.
Added 5 starter pages to the main and footer menus.
```

## For developers

- `Page\Provisioning\StarterPages` listens to `SiteProvisioning` at priority `CONTENT` (0); the templates are in
  `resources/views/page/starter/<person|organization>/<slug>.html.twig` (each renders a rich text block's HTML).
- `Menu\Provisioning\StarterMenus` runs after it (priority -10) and only fills menus that are still empty.
- Tests: `tests/Unit/Page/Provisioning/StarterPagesTest.php`, `tests/Unit/Menu/Provisioning/StarterMenusTest.php`,
  `tests/Integration/Page/StarterContentTest.php`, `tests/A11y/starter.spec.ts`.
