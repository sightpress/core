# Contributing to SightPress core

Thank you for helping. SightPress is a CMS for everyone, designed with blind and low-vision creators and audiences in
mind, and it needs much more than code. Testers, translators, writers, designers and people who simply found
something that doesn't work are all contributors.

Everyone who takes part agrees to follow our [Code of Conduct](CODE_OF_CONDUCT.md).

## Ways to help

### Report a problem

For anyone who found something that doesn't work or shows an error.
[Open an issue](https://github.com/sightpress/core/issues/new) and tell us:

- what you were doing;
- what happened, including any error message, copied as text;
- what you expected to happen;
- the SightPress version, and if you know them, the PHP version, the database and the browser.

A maintainer replies, asks for anything that is missing and labels the issue. If you're not sure which repository the
problem belongs to, open it in [core](https://github.com/sightpress/core/issues/new) and we'll move it.

### Report an accessibility barrier

For anyone who can't do something because of how SightPress works with their assistive technology, their keyboard,
their zoom level or their colour settings. Accessibility barriers are always treated as bugs, never as feature
requests. Open an issue and tell us:

- the task you were trying to do;
- your assistive technology and its version, if you use one: screen reader, magnifier, braille display, voice
  control, switch access, or keyboard only;
- your browser and operating system;
- what was announced or what happened;
- what you expected.

### Report a security problem

Never report a security problem in a public issue. Email <security@sightpress.org> with what you found and how to
reproduce it. We acknowledge every report within 5 working days and tell you what we will do about it.

### Translate SightPress

For people who are fluent in a language. The admin panel and the website screens are translated with XLIFF files.
British English (`en-GB`) is the source language and the last fallback. Regional variants are separate languages: for
example `pt-PT` and `pt-BR`, or `es` and `es-419`. Every new language is reviewed by a fluent speaker before it is
released, and right-to-left languages also get a visual check. Send a pull request, or email the translated file to
<contribute@sightpress.org>.

### Review the interface

For sighted people. SightPress is led by blind people, so we need sighted reviewers to check what is hard to check
without sight:

- colour contrast, in light mode, dark mode and forced colours (Windows contrast themes);
- that the keyboard focus is always visible;
- spacing and alignment, and text that is cut off or overlaps at 200% and 400% zoom or on a 320-pixel-wide screen;
- icons, generated images (avatars, sharing images, charts) and screenshots in the documentation;
- that the label you can see matches what a screen reader announces.

Open an issue with the page or screen, your browser, zoom level and colour mode, what looks wrong and, if you can, a
suggested fix. If you attach a screenshot, add a text description of what it shows: whoever reads the issue may not
be able to see it.

### Test with assistive technology

For people who use a screen reader, a magnifier, a braille display, voice control or switch access. Try new screens
and release candidates and report what you find, as for an accessibility barrier.

### Improve the documentation

For anyone who can write clear English. Fix mistakes, write how-to guides for people who aren't developers, or review
the wording of the admin panel and its error messages so that they are plain and easy to understand. The
documentation lives in the [docs](https://github.com/sightpress/docs) repository, and small fixes need no developer
set-up: use the edit button on any file in GitHub.

### Write code

For developers. Fix bugs and build features by pull request:

- for anything large, open an issue first so that we can agree on the approach;
- one change per pull request, on its own branch;
- commit messages and pull request titles follow [Conventional Commits](https://www.conventionalcommits.org/)
  (`feat:`, `fix:`, `docs:`, `chore:` and so on), because releases and the changelog are built from them;
- every check must pass (see [Working on this repository](#working-on-this-repository));
- for any change to a screen, the accessibility tests must report no violations, and the pull request description
  says how you went through the change with the keyboard alone;
- one approving review is needed before a pull request is merged. Pull requests are squash-merged.

### Build a theme or a module

Themes and modules live in their own repositories and are installed as Composer packages. Themes are built against
[theme-interface](https://github.com/sightpress/theme-interface) and modules against
[module-interface](https://github.com/sightpress/module-interface). Templates and a tutorial for both are on the way;
until then, each interface's README explains the contracts. Themes and modules follow the same accessibility,
security and testing rules as SightPress itself.

### Share an idea or ask a question

Open an issue and say that it's an idea or a question. We'll talk it through with you there.

## If you can't use GitHub

If GitHub's forms don't work for you, or you'd rather not create an account, email your report, translation or idea
to <contribute@sightpress.org>. A maintainer will add it to GitHub for you and keep you up to date.

## Working on this repository

This is SightPress core: the kernel, the module loader and the built-in modules. The [README](README.md#development)
has the full set-up. In short:

- Clone [module-interface](https://github.com/sightpress/module-interface) next to this repository, then run
  `composer install` and `composer check`. `composer check` must pass before every commit: it checks the code style,
  runs static analysis and runs the unit and integration tests. Unit tests must cover 100% of `src/`.
- If your PHP has no coverage extension, run the checks in the development image in `docker/dev-php/`.
- For any change to a screen, start the [skeleton](https://github.com/sightpress/skeleton) next to this repository
  and run `composer a11y`. It must report no accessibility violations (WCAG 2.2 AA).
- Translations live in `resources/translations/`, one `messages.<locale>.xlf` file per language.
  `messages.en-GB.xlf` is the source.

## Licence

By contributing, you agree that your contribution is shared under the GNU General Public License v3.0 or later.
See [LICENSE](LICENSE).
