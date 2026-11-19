# Accessible charts (AN-2)

The analytics screens show their numbers as charts that everyone can use: drawn on the server as SVG, with no
JavaScript and no chart library. ADR-0038.

## What every chart has

- A **summary in words** right above it, for example "Visitors went up 12% compared with the previous 30 days,
  from 1,204 to 1,349. The busiest day was Tuesday 4 November, with 98 visitors." When there is nothing to show,
  only that sentence is there, never an empty chart.
- A **name** for screen readers ("Line chart of visitors per day, 15 October 2026 to 13 November 2026"), and the
  summary as its description.
- **The same data as a table**: "Show the data for each day" opens a table with every day; top lists (devices,
  languages, pages …) are tables first, with a small bar next to each number for sighted readers.
- **More than colour**: this period is a solid line with round markers, the previous period a dashed line with
  square markers, and a key under the chart says so in words.
- Real text that keeps the page's size and grows with zoom; the chart reflows down to 320 px wide.
- Numbers and dates in the reader's language (`1,349` / `1 349` / `1.349`).
- In Windows High Contrast and other forced colour modes, the chart uses the system colours.
- No animation.

## For theme authors

`theme.json` needs six more colours in `colors.light` and `colors.dark` (and may change them in `colors.more`):

| Token | Used for | Contrast needed |
|---|---|---|
| `chart-1` | this period's line, markers and bars | 3:1 on `background` and `surface` |
| `chart-2` | the previous period's line and markers | 3:1 on `background` and `surface` |
| `chart-3`, `chart-4` | more series (reserved) | 3:1 on `background` and `surface` |
| `chart-grid` | gridlines (decorative) | none |
| `chart-text` | axis labels and bar labels | 4.5:1 on `background` and `surface` |

`theme:validate` checks them in every mode and refuses a theme without them. The CSS classes are `chart`,
`chart__summary`, `chart__svg`, `chart__series--current`, `chart__series--previous`, `chart__bar`, `chart__key`,
`chart-data` and `chart-data__table` (see the default theme's `site.css`).

## Checking it

The component gallery's chart style guide (`/charts`, `tests/A11y/charts.spec.ts`) shows every kind of chart,
including one day, a range of zeros and an empty range.
