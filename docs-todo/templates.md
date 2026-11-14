# Templates (C-1.3)

## What it does

Pages are built from templates. SightPress looks for a template in the site's theme first, then in the default
theme, then in core, so a theme only needs the templates it changes. Everything a template prints is escaped, so
text people type can never turn into code on the page.

## Who uses it

- **Theme authors** override templates and use the functions below.
- **Module authors** add a folder of templates under a namespace (`@acme/…`) with the view registrar; themes can
  override them in `templates/modules/acme/`.

## Where templates are found

| Order | Folder | Example |
| --- | --- | --- |
| 1 | Site theme | `<theme>/templates/page.html.twig` |
| 2 | Default theme | `<default theme>/templates/page.html.twig` |
| 3 | Core | `core/resources/views/page.html.twig` |

For a module namespace: `<theme>/templates/modules/<namespace>/…` (site theme, then default theme), then the
module's own folder.

## Functions and filters

| Name | What it does | Example |
| --- | --- | --- |
| `asset(path)` | URL of a file in the assets folder, with a version so browsers get updates | `{{ asset('css/site.css') }}` |
| `t(text, values)` | The text in the viewer's language; ICU plurals and `{placeholders}` | `{{ t('{count, plural, one {# page} other {# pages}}', {count: n}) }}` |
| `can(permission)` | Whether the viewer holds a permission | `{% if can('page.publish') %}` |
| `csp_nonce()` | The nonce every inline `<script>` or `<style>` needs | `<script nonce="{{ csp_nonce() }}">` |
| `\|user_datetime(style)` | A date and time in the viewer's time zone and language | `{{ page.updatedAt\|user_datetime }}` → 14 Nov 2026, 17:05:00 |
| `\|user_date(style)` | A date in the viewer's time zone and language | `{{ page.publishedAt\|user_date }}` → 14 November 2026 |

Styles: `short`, `medium`, `long`, `full`. Dates are stored in UTC; signed-in people see their own time zone and
language, visitors see the site's.

Write dates in a `<time>` element so assistive technology and search engines get the exact value:
`<time datetime="{{ page.publishedAt|date('c') }}">{{ page.publishedAt|user_date }}</time>`.

## Translations

The en-GB text is the message id. Translations are PHP files named `<domain>.<locale>.php` that return
`['English text' => 'Translated text']`; core's are in `resources/translations/`. A missing translation shows the
en-GB text.

## Accessibility

Templates must meet WCAG 2.2 AA: `lang` on `<html>`, one `<h1>`, landmarks, labelled controls. Never use `|raw` on
text people typed. The CSP blocks inline scripts and styles without `nonce="{{ csp_nonce() }}"`.

## Code and tests

- `src/Template/`, `src/Http/CurrentRequest.php`, `src/Http/Security/GrantedPermissions.php`
- `tests/Unit/Template/`, `tests/Integration/Http/ModuleTemplateTest.php`
