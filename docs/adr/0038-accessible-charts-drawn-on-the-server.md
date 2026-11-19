# 0038 — Accessible charts drawn on the server as SVG

- Status: Proposed
- Date: 2026-11-19

## Context

Plan §1.2 (*Accessible charts*) asks for analytics charts with no JavaScript and no chart library, a plain-language
summary and the data as a table for every chart, series that differ by more than colour, text of at least 14 px
that grows with zoom, reflow at 320 px, colours from theme tokens checked for contrast, and `forced-colors`
support. AN-2 builds them. An SVG scaled through a `viewBox` makes its text shrink with the chart: at 320 px a
640-unit-wide chart would draw 14-unit text at about 7 px.

## Decision

- **Draw with percentages, without a `viewBox`.** `ChartRenderer` places lines, markers, gridlines and text in
  percentages of the chart's width and height (the height is set in `rem` by CSS). The chart stretches to any
  width, and its `<text>` keeps the page's font size (`--font-size-small`) and grows with zoom. Square markers
  are small nested `<svg>` elements placed by percentage, because a `<rect>` can't be centred on a percentage.
- **Line charts:** this period is a solid line with round markers (`chart-1`), the previous period a dashed line
  with square markers (`chart-2`). Markers are left out beyond 31 days. The y axis has three gridlines (0, half and a
  round top, at least 4); the top one is labelled with its unit ("100 visitors"). The x axis labels the first, middle
  and last days in the viewer's language. There is no colour legend: a text key under the chart names each series
  by its line and marker ("Last 30 days: solid line with round markers").
- **Bar charts:** each bar has its label and amount written above it. Top lists are tables first, with a
  decorative inline bar (`aria-hidden`) next to each number.
- **Every chart** is a `<figure>`. Its `<figcaption>` holds the summary from `ChartSummary`: the total, the change
  compared with the previous period in words, and the busiest and quietest days, or a sentence when there is
  nothing to show. The `<svg>` is `role="img"` with an `aria-label` ("Line chart of visitors per day, 15 October
  2026 to 13 November 2026") and `aria-describedby` pointing to the summary. Daily data is in a
  `<details>` "Show the data for each day" with a `<table>` (caption, `th scope`). An empty range draws no chart,
  only the sentence. There is no animation.
- **Text is translatable and locale-aware:** sentences are ICU messages in the interface catalogues; numbers,
  percentages and days use `NumberFormatter`, `IntlDatePatternGenerator` and `IntlDateFormatter` for the viewer's
  locale (`ChartFormat`). Days are `Y-m-d` already in the site's time zone and are formatted as UTC, so they never
  shift.
- **Theme tokens:** `chart-1` … `chart-4`, `chart-grid` and `chart-text` are colours in `theme.json` (light, dark and
  both "more contrast" sets), so they become `--color-chart-*`. `ThemeValidator` always checks them, whether or not
  the theme lists them under `contrast`: lines and bars 3:1 and chart text 4.5:1 on the background and the surface.
  A theme without them fails. Gridlines are decorative and have no contrast rule.
- **Forced colours:** charts opt out of automatic adjustment and use system colours (`CanvasText` for lines, marks
  and text, `GrayText` for gridlines); line style and marker shape still tell the series apart.
- **Twig macros** `@analytics/charts.html.twig` (`line`, `bar`, `table`) render the view-models `ChartView` builds.
  The chart classes stay in the analytics module for now. Another module that wants them needs a contract first.
- **Tests:** golden SVG files for each kind of chart (a week with the week before, one day, all zero, bars, bars of
  zero), summary sentences for up, down, flat, from zero and no data, and a chart style guide page (`/charts`) on the
  component gallery that Playwright and axe check in light, dark, more contrast, forced colours and at 320 px.

## Consequences

- Charts look the same with or without JavaScript and are cheap to draw. Lines between percentages are drawn as
  separate segments, so the dash pattern starts again at each day.
- On very narrow screens the x axis shows only three dates; the table always has every day.
- Third-party themes must add the chart colours (`theme-interface` 0.2 should describe them, gap 6).
