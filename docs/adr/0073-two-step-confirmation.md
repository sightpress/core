# 0073 — Two-step confirmation for important changes

- Status: Proposed
- Date: 2026-11-25

## Context

Plan §1.2 *Two-step confirmation*, N-6, D33–D37 and stage-4 gaps 9 and 10. A stolen password should not be enough to
change who controls a site. module-interface 0.5 already lets a module mark a route `critical` (MI-0.5); core needs
the list of its own critical actions, the confirmation itself, and a way back for people who lose their phone or
when no code can be sent.

## Decision

- **Who switches it.** Tenant setting `two_step`: `optional` (default; sites choose), `required` (every site with text
  messages) or `off` (no site; the screen doesn't exist). Validated at boot. Sites choose on *Site settings → Security*
  (`security.manage`, admins). It only works while text messages are on. Turning it on needs your own confirmed
  number and a code, so an admin can't lock the site out with a number that doesn't work; turning it off is a critical
  action.
- **What is critical.** Core's list (`Auth\StepUp\CriticalActions`): your password, email address, or changing or
  removing your number (adding a first one isn't: there is nothing to send a code to yet); inviting an admin or editor;
  a person's role or status, removing their number; editing roles; who can read the site; who may sign up; security
  settings; turning features on or off and deleting their data; the AI assistant's service and key; cloud storage;
  deleting analytics data. Plus every module route marked `critical`.
- **How.** `Http\Security\StepUpMiddleware` runs after routing and the CSRF check, for changes (`POST`, `PUT`,
  `PATCH`, `DELETE`) by signed-in people. While two-step is on and the session has no confirmation from the last
  **10 minutes**, nothing is written: the form is kept in the session **without passwords, codes or files**, and the
  person goes to *Confirm it's you* (`/confirm`). "Send me a code" texts a 6-digit code (`VerificationPurpose::StepUp`,
  10 minutes, no link); the right code confirms this session only (bound to its id, which signing in replaces). Then
  *Continue* (`/confirm/continue`) shows the kept form ready to send again with one button, or a link back to it when a
  password or file must be given again. No generic prefill of every form: one button is simpler and works for all.
- **No number.** The person adds one first (`/account/phone`, then back to `/confirm`). **No email fallback** (D35).
- **Lost phone, no code (gap 10).** Someone with `user.manage` removes the person's number (*People → person*, itself
  critical) or the operator runs `site:user:phone:reset <site> <email>`; the person then adds a new one. When no code
  can be sent (provider down, budget used up), the page says so and that whoever runs the sites can make the change
  from the console, which two-step never stops. Security codes keep a reserve of the budget (ADR-0069); STOP doesn't
  stop codes, and the page tells people whose number blocks texts to reply START.
- **Gap 9.** While two-step is on, nobody resets their password by text: the phone is the second step.
- **Audit.** Every code typed, right or wrong, with the number masked; the setting change by key.

## Consequences

- Important changes cost one text and a few seconds every 10 minutes.
- A form whose handler needs a password (change password or email) must have it typed again after confirming.
- Modules get protection by marking their routes `critical`; no module code needed.
