# Starter pages in every language (I-4)

Every new site gets six starter pages — Home, About, Contact (with a contact form), Privacy, Terms and an Accessibility
statement — for a person or an organisation. They are now written in each of the site's languages.

- `site:create … --locale=en-GB,pt-PT,es` (the main language first) writes all six in every language and publishes them,
  at each language's own addresses (`/pt-pt/sobre`, `/es/condiciones`), with links between them in the same language
  and the menus showing them in the page's language. Every page passes the publish checks.
- Starter content exists in British English, European Portuguese and Spanish. Another language gets the closest one
  (Brazilian Portuguese gets European Portuguese), otherwise British English, and `site:create` prints a warning.
- **A language added later:** the Languages screen (`/admin/settings/languages`) shows **Create the starter pages in
  <language>** for each language that hasn't got them; on the command line, `site:locale:add <site> <locale>
  --starter-pages`. They are written as **drafts** of their own: nothing is published until each translation is
  published from its page's editor. Adding a removed language back keeps its old translations.
- Where they come from: `resources/views/page/starter/<owner>/<locale>/`, `Page\Contract\StarterContent`; the site
  setting `page.starter` remembers the pages, the owner, the address and the contact form's recipient.

Tests: `tests/Integration/Page/StarterContentTest.php` (three languages, six pages each, publish checks, drafts for a
language added later), `tests/Unit/Page/Provisioning/StarterPagesTest.php`, `tests/A11y/languages.spec.ts`.
