# Forms and validation (C-1.4)

## What it does

Every form in SightPress is built from the same accessible pieces. People see a clear label for each field, a
short hint where it helps, and — if something is wrong — a list of problems at the top of the page that links to
each field, plus the message next to the field itself. Screen readers announce the list first and read each
field's hint and error with it.

## Who uses it

- **Everyone filling in a form** (sign-in, profile, pages, settings …).
- **Developers** define forms with `Form` and `Field`, check them with `FormValidator` and render them with
  `form/macros.html.twig`.

## How a form behaves

1. Required fields are the norm; optional fields say "(optional)" in their label.
2. The browser's own pop-up validation is off; the server checks everything and explains each problem in words.
3. After a failed submit:
   - the page title starts with "Error:";
   - a box titled **There is a problem** at the top gets focus and lists every problem as a link;
   - each link moves focus to its field;
   - each field with a problem shows the message (read as "Error: …") and is marked invalid;
   - what the person typed is kept, except passwords.

## Messages

| Field | Message |
| --- | --- |
| Any required field left empty | "Enter …" or "Select …" followed by the label, or the field's own message |
| Email | Enter an email address in the right format, like name@example.com |
| Web address | Enter a web address in the right format, like https://example.com |
| Number | Enter a number for "…" |
| Date | Enter a real date for "…" |
| Select / radios | Select one of the options for "…" |

## Field types

Text, email, password, web address, number, telephone, date, multi-line text, select, radios, checkbox, checkboxes.
Radios and checkboxes are grouped in a `<fieldset>` whose `<legend>` is the question.

## For developers

```php
$form = Form::create('profile',
    new Field('email', 'Email address', FieldType::Email, hint: 'We only use it to sign you in.', autocomplete: 'email'),
    new Field('bio', 'Biography', FieldType::Textarea, required: false),
);
$form = $validator->submit($form, (array) $request->getParsedBody());
if ($form->isValid()) { /* save */ }
```

```twig
{% import 'form/macros.html.twig' as f %}
{{ f.error_summary(form) }}
{{ f.start(form, '/profile') }}
{{ f.field(form, 'email') }}
{{ f.field(form, 'bio') }}
{{ f.submit(t('Save profile')) }}
{{ f.end() }}
```

`f.start()` adds the CSRF fields to `POST` forms. Errors found later (for example "That email address is already
used") are added with `$form->withErrors([...])`. Put `{% if form.errors is not empty %}Error: {% endif %}` at the
start of the page `<title>`.

## Code and tests

- `src/Http/Form/`, `resources/views/form/macros.html.twig`, `csrf_fields()` in `src/Template/TemplateExtension.php`
- `tests/Unit/Http/Form/`, browser tests in `tests/A11y/form.spec.ts` against the component gallery
  (`tests/A11y/gallery/`)
