# 0039 — Dashboard widgets drawn by the admin, and the Analytics screen

- Status: Proposed
- Date: 2026-11-19

## Context

`module-interface` 0.4 added `DashboardWidget`, `DashboardContext` and `dashboard()` (ADR-0023), and the loader has
collected widgets since then, but nothing showed them. AN-3 adds the first one (analytics' "Last 7 days") and the
Analytics screen. The admin module may not use the module loader (ADR-0009). Analytics may not use the admin
module's internals.

## Decision

- **`Infrastructure\Dashboard\DashboardWidgets`** (shared code, in every site container, like `SearchExtensions`)
  builds the widgets of the site's modules that are on, sorted by position and title. It also turns a route name
  into its path for `DashboardContext::url()`.
- **The dashboard shows widgets first**, each as a `<section>` named by its `<h2>` (the title translated), only when
  the person holds the widget's permission. `AdminDashboardContext` gives the admin language, time zone, permissions
  and route paths. A widget that throws is left out and logged with its id, so one module can't break the dashboard.
- **The Analytics screen** (`/admin/analytics`, `analytics.view`):
  - The range is in the address: presets `?range=7|30|90|365` (links with `aria-current="page"`), or `?from=&to=`
    from a GET form with two labelled date fields. An unusable range keeps the last 30 days and shows the errors in
    the error summary.
  - Headline figures are sentences ("1,349 visitors, up 12% on the 30 days before"); the arrows are `aria-hidden`.
  - One `<section>` with an `<h2>` per report. Top lists are tables with a decorative bar, at most 10 rows on screen.
    Languages only show on sites with several languages, and site search only while search is on.
  - "Pages not found" links to `/admin/redirects?from=<path>`, which now fills in the old address.
  - Each report has a CSV at `/admin/analytics/download?report=…` with the screen's range. It comes from the same
    `ReportTable` as `site:analytics:report`, and the site's time zone is named in the header row.
- **Retention and deletion** (`analytics.manage`): a number field (1–25) posted to `/admin/analytics/retention`, and
  `/admin/analytics/delete` with a confirmation page. Deleting dispatches `Analytics\Contract\AnalyticsDataDeleted`,
  which the audit log records ("Deleted all analytics data.").
- The analytics module now needs the admin module.

## Consequences

- Any module can add a dashboard widget through the contract; core draws it accessibly around the widget's own HTML.
- Analytics works out the admin language from `ViewerPreferences` and `Translations`, as the admin shell does,
  because `AdminScreens` has no way to give the viewer. A `viewer()` on the contract could replace this later.
- Deleting analytics isn't one of the "critical actions" yet; two-step confirmation (plan §1.2) adds it later.
