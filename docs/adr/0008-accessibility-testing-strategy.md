# 0008 — Accessibility testing strategy

- Status: Accepted
- Date: 2026-11-13

## Context

WCAG 2.2 AA is our minimum, for the public site and the admin. Automated tools catch only part of the problems
(roughly a third of WCAG issues); keyboard flow, focus, announcements and the meaning of text need people and
screen readers. We need checks that run on every pull request, plus regular human checks that can't be skipped.

## Decision

- **Automated, every PR (CI gate):**
  - Playwright with axe-core, using the WCAG 2.0, 2.1 and 2.2 A and AA rule tags, on every public page type and
    every admin screen, signed in as each role. Any violation fails the build.
  - Runs in light mode, dark mode and `forced-colors`, and at 320 px width.
  - Playwright keyboard tests for every interactive component and key flow (block editor, menu editor, dialogs,
    forms with errors): they check focus order, visible focus and where focus lands after each action.
  - Unit tests for our own accessibility logic: theme contrast validation, publish-time checks (heading order,
    alt text, link text), generated colours.
- **Per UI pull request:** the description records a keyboard walkthrough of the change.
- **Per milestone (human, before the release is tagged):** a screen reader pass with NVDA + Firefox and
  VoiceOver + Safari on the admin and the public site. Problems become issues that block the release.
- **Before 1.0:** an external review that includes blind and low-vision users, and a published accessibility
  conformance report.
- Run the checks against the skeleton started with docker compose, so tests see what users see.

## Consequences

- Every UI task takes longer, and that is intended.
- CI needs a browser and the skeleton running; the a11y job is slower than the PHP jobs.
- Passing axe is necessary but not enough; the human checks stay in the release checklist.
