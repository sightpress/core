# 0040 — AI assistant: providers over the shared HTTP client, keys, budget and limits

- Status: Proposed
- Date: 2026-11-20

## Context

ADR-0010 decided what the optional AI assistant is (off by default, bring your own key, suggestions only) and
ADR-0023 the contracts in `module-interface` 0.4. A-2 builds the module: the providers core ships, where the key and
the choices are kept, how a site inherits its organisation's choice, what stops runaway costs, and how requests to a
self-hosted model can't be turned against the server's own network (SSRF). The plan named Guzzle (PSR-18) for the
providers; core already has `symfony/http-client` for IndexNow (ADR-0015), and GDPR work (GD-1) wants every call to a
third party to go through one place.

## Decision

- **One outgoing HTTP client.** Providers use the tenant's `HttpClientInterface` (`symfony/http-client`, already in
  the baseline), not Guzzle: no new dependency, and GD-1 can wrap that one service. `AssistantHttp` adds a 30-second
  idle timeout, 90 seconds in all, no redirects, and up to 2 retries only when the service says it is busy (429,
  500, 502, 503, 504, 529), doubling from 0.5 s; never after a timeout or network error, and not when `Retry-After`
  asks for more than 5 s (the person is told to try again later instead). Tests use `MockHttpClient`; CI never calls
  a provider.
- **Core's providers**: `anthropic` (Messages API, `anthropic-version: 2026-10-21`, no temperature), `openai`,
  `mistral` and `openai_compatible` (chat completions; the last needs a base URL and has no model list). Model lists
  live in `resources/assistant/providers.php` with whether each model can see pictures; none is preselected (D15).
  Errors map to `AssistantFailure` from the HTTP status and the service's error type; exception messages name the
  service and the status only.
- **SSRF guard** (`EndpointGuard`): HTTPS only (plain HTTP only in `dev`), no user name or password in the URL, and
  unless the tenant sets `assistant.allow_private_hosts`, every address of the host must be public (no private,
  loopback, link-local, shared or reserved ranges, IPv4-mapped IPv6 included). The checked address is the one
  connected to (`resolve`), so DNS rebinding can't swap it.
- **Settings**: the site's `assistant.provider|model|base_url|key|monthly_budget|roles` in its settings table; the key
  encrypted with the tenant's `APP_SECRET` (`SecretBox`), never shown again (only `••••1234`), never in logs, audit
  diffs (`SettingsChanged` lists keys only) or CLI output. The tenant's `assistant` section gives a default
  (provider, model, base URL, key from `ASSISTANT_API_KEY`), `site_keys` (false = sites can't choose) and
  `monthly_budget`; a site inherits the tenant key only for the tenant's provider.
- **Who may use it**: `assistant.use` (admin, editor, contributor) and the task's own permission, and the person's
  role must be one of the site's assistant roles (admins always). `assistant.manage` (admin) for the settings.
  `assistant.use` is sign-up-safe (ADR-0014), so `contributor` stays a role people who sign up may get: what a
  stranger can spend is bounded by the rate limit, the monthly budget and the site's assistant roles, which an admin
  who opens sign-up can narrow to editors.
- **Limits**: 20 requests per person per 10 minutes (`assistant` rate limit); a monthly token budget per site (UTC
  month, input + output tokens; 0 = no limit) with a warning from 80 % and a refusal at 100 %.
- **Usage**: `assistant_usage` keeps one row per call (time, month, user id, task, provider, model, tokens,
  outcome), never the prompt, the answer, an IP address or a user agent. Every call that reaches a provider is
  audited (`assistant.used`) with the same facts.
- **Inputs and outputs**: a task's input is checked against its schema (`InputSchema`, a strict subset of JSON
  Schema that refuses keywords it doesn't know) before the prompt is built; page content goes into `<data>` blocks
  that the system message calls data, never instructions (`PromptData`); answers are checked by the task.
- **Console**: `site:assistant:set` (key from standard input with `--key-stdin`), `site:assistant:disable
  [--remove-key]`, `site:assistant:test`, `site:assistant:usage [--month=]`, `assistant:ask`.

## Consequences

- A site that never turns the assistant on has no assistant table rows, routes or providers and contacts nobody.
- Changing the `APP_SECRET` makes stored keys unreadable: the admin sees "no key" and enters it again.
- A provider that only works through redirects, or answers slower than 30 s between bytes, won't work.
- `openai_compatible` models are treated as unable to see pictures, so alt text suggestions need a listed model.
- The model list needs a `fix:` release when providers retire models; until then a retired model shows the
  plain-language "model not available" message.
