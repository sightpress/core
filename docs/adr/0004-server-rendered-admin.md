# 0004 — Server-rendered admin panel (no SPA)

- Status: Proposed
- Date: 2026-11-13

## Context

The admin panel is where creators, many of them blind or low-vision, spend most of their time. Single-page apps
often break what screen readers and keyboards rely on: page titles that don't change, focus that gets lost after
an update, content that appears without being announced, and no use at all when JavaScript fails. They also need
a build step and a second API layer to secure.

## Decision

- Render the admin panel on the server with Twig under `/admin`. Every screen is a real URL with its own `<title>`.
- Use plain HTML forms with POST, CSRF tokens and the Post/Redirect/Get pattern. Every task works with JavaScript
  turned off.
- Use JavaScript only as progressive enhancement, in small modules with no framework and no build step required:
  for example disclosure buttons, character counters and background form submits.
- When JavaScript changes the page, manage focus and announce the result in a live region, the same way a full
  page load would.
- Show errors in an error summary at the top of the form, linked to each field, and put the error count in the
  page title.
- Use native elements first (`<details>`, `<dialog>`, `<input type=file>`, `<select>`), and ARIA only where HTML
  has no element for the job.
- Don't build a public JSON API for the admin. A JSON API, if needed later, is a separate decision.

## Consequences

- Screens behave the same for every user and every assistive technology, and can be tested with Playwright + axe
  without a browser-side app.
- One security model (session, CSRF, server-side authorisation) covers the whole admin.
- Rich interactions (drag and drop, live preview) are harder; each one needs a button-based alternative first.
- The rich text editor starts as an accessible textarea with a preview; a richer editor needs its own
  accessibility review before it's added.
