# Security headers, sessions, forms and rate limits (C-1.2)

## What it does

Every response SightPress sends protects visitors by default: browsers are told what the page may load, that it
must not be shown inside another site, and (over HTTPS) to keep using HTTPS. Signed-in people get a session cookie
that scripts can't read; anonymous visitors of public pages get no cookie at all. Every form carries a hidden
token so other sites can't send it on someone's behalf, and too many form submissions from one address in a short
time are slowed down.

## Who uses it

- **Visitors and editors** benefit without doing anything. If a form was open too long, they see "This form has
  expired" and reload the page.
- **Operators** can set where sessions are kept and how long an idle session lasts.
- **Developers** add `nonce="{{ csp_nonce() }}"` to any inline `<script>` or `<style>` and include the CSRF
  fields in every form (both come with the template helpers in C-1.3 / C-1.4).

## Settings (`config/tenant.php`)

| Key | Default | Meaning |
| --- | --- | --- |
| `session.path` | `var/sessions` | Folder for session files (one file per session, readable only by the server). |
| `session.idle_timeout` | `7200` | Seconds without a request before a session ends (at least 60). |

## Headers

| Header | Value |
| --- | --- |
| `Content-Security-Policy` | Only this site's own files; inline scripts and styles need the page's nonce; no plugins, no framing, forms only post to this site. Adds `upgrade-insecure-requests` over HTTPS. |
| `Strict-Transport-Security` | `max-age=31536000` (HTTPS only). |
| `X-Content-Type-Options` | `nosniff` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `Permissions-Policy` | Camera, microphone, location, payment, USB, motion sensors and topics are off. |
| `Cross-Origin-Opener-Policy` | `same-origin` |
| `X-Frame-Options` | `DENY` |

Error pages keep their own, stricter policy.

## Sessions

- The cookie is `HttpOnly` and `SameSite=Lax`; over HTTPS it is `Secure` and named `__Host-sightpress_session`.
- It lasts until the browser closes; the server ends it after the idle timeout.
- A session only starts when something is stored in it (for example a form token or signing in). Responses that
  use a session are marked `Cache-Control: private, no-store`.
- Signing in gives the session a new id; signing out deletes it and its cookie.

## Forms (CSRF)

Every `POST`, `PUT`, `PATCH` and `DELETE` request must carry a valid token (fields `csrf_name` and `csrf_value`, or
headers of the same names). Without one the answer is **403 — This form has expired**: "For your security, a
form only works for a while after you open it, and only on this website. Go back, reload the page and send the
form again." Tokens stay valid for the whole session, so several open forms and the back button keep working.

## Rate limits

| Policy | Limit | Used for |
| --- | --- | --- |
| `write` | 60 requests a minute per address | Every state-changing request |
| `sign_in` | 5 attempts in 15 minutes | The sign-in form (C-3.3) |

Over the limit the answer is **429 — Too many requests** with `Retry-After`, and the page says when to try again
("Try again in a minute.").

## Code and tests

- `src/Http/Security/`, `src/Http/Session/`, `src/Http/Csrf/`, `src/Http/RateLimit/`,
  `src/Infrastructure/Clock/SystemClock.php`
- `tests/Unit/Http/{Security,Session,Csrf,RateLimit}/`, `tests/Integration/Http/SecurityPipelineTest.php`
