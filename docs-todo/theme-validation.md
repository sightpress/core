# Theme validation (C-6.5)

## What it does

Before a theme is used, SightPress checks that people can read it. Every pair of colours the theme puts together
— text on a background, a border or focus ring next to a background — must have enough contrast in light mode,
dark mode, and both with "more contrast" switched on. The base font size must be at least 16 pixels. A theme
that fails is refused, with a sentence for each problem saying which colours, in which mode, and what the
contrast is.

## Who uses it

- **Operators and theme authors**, with `bin/sightpress theme:validate` (now).
- **Site admins**, when the admin can apply themes and change colours (later milestones): the same checks run
  before anything is saved.

## The rules (WCAG 2.2 AA)

| Use | Minimum | Success criterion |
| --- | --- | --- |
| `text` — text, links, text on buttons | 4.5:1 | 1.4.3 Contrast (Minimum) |
| `ui` — borders of controls, focus rings, icons, button backgrounds next to the page | 3:1 | 1.4.11 Non-text Contrast |
| Base font size (`typography.size-base`) | 16 px (1rem) | — (SightPress rule) |

Ratios are compared exactly: 4.49:1 fails, even though it would round to 4.5. Messages show ratios rounded down.
The font size must be written in `rem` or `px` so it can be checked.

## Declaring pairs in `theme.json`

```json
"contrast": {
  "text": [["text", "background"], ["on-primary", "primary"]],
  "ui": [["border", "background"], ["focus", "background"]]
}
```

Each name must be a colour in `colors.light`. A theme that declares no pairs fails, because nothing could be
checked. The default theme declares 33 pairs (132 checks across the four modes).

## CLI

```
bin/sightpress theme:validate [<path>]
```

- Without `<path>` it checks the active theme (the default theme in this version).
- Exit code 0 and one line when it passes:
  `The theme "SightPress Default" passes: 132 colour pairs checked in light and dark mode, with and without more contrast, and the base font size is at least 16 px.`
- Exit code 1 when it fails, with each problem on standard error, for example:
  `1. In light mode, text "text-muted" (#9a9aa5) on "background" (#ffffff) has a contrast of 2.78:1; it needs at least 4.50:1.`

The output is plain sentences (no tables or colours), so it reads well with a screen reader.

## Code and tests

- `src/Theme/ThemeValidator.php`, `ThemeValidation`, `ThemeIssue`, `ContrastPair`, `ContrastUse`,
  `Color::contrastWith()`, `Command/ValidateThemeCommand.php`
- `tests/Unit/Theme/ThemeValidatorTest.php`, `tests/Unit/Theme/Command/`, `tests/Integration/Theme/ValidateThemeCommandTest.php`
