# 0010 — AI assistant

- Status: Accepted
- Date: 2026-11-13

## Context

An assistant can help creators write summaries, meta descriptions and alt text, draft pages, explain publish
checks in plain language, and answer "how do I …" questions. That is especially useful for blind and low-vision
creators. But it sends content to a third party, costs money, can be wrong, and reads text that may contain
hostile instructions (prompt injection). Many sites will not want it at all.

## Decision

- **Optional and off by default.** The assistant is an optional module (`assistant`), never `required`, and off
  for every new site. A site admin turns it on.
- **Bring your own key.** No SightPress-owned key or service. A site admin chooses a provider and model and enters
  an API key. The tenant can set a default (key from the environment) that sites inherit, and can forbid per-site
  keys. No provider is preselected: the admin chooses after reading the privacy notice.
- **Providers behind a contract.** Providers implement an `AssistantProvider` contract in `module-interface`.
  Core ships adapters for Anthropic, OpenAI, Mistral and any OpenAI-compatible endpoint. Model lists live in a
  maintained resource file, not in code.
- **Tasks behind a contract.** Each feature is an `AssistantTask` (id, required permission, input schema, prompt,
  output validator) registered through the module registrar, so modules can add their own.
- **No state-changing tools.** The assistant can't publish, delete, or change settings, users, roles or emails.
  There is no code path from its output to those actions.
- **Human in the loop.** Output is always a suggestion with *Insert*, *Copy*, *Try again* and *Discard*.
  Inserting fills the form as an unsaved edit, so validation, RBAC, workflow and publish checks apply as usual.
  Alt text written by the assistant fails the publish check until a person confirms it.
- **Untrusted output.** Output is validated and sanitised like user input; block data is validated against the
  block schemas. Page content in prompts is delimited and treated as data.
- **Privacy defaults.** Send only the user's request and the context they choose (the current page, block or
  image). Never send user lists, emails, audit data or other pages. Show a plain-language notice of what is sent,
  to whom, where it is processed and how long it is kept.
- **Secrets and logs.** Keys are encrypted at rest, write-only in the UI, and never appear in logs, exceptions,
  audit diffs or CLI output. Prompt and response text is never logged or audited; usage is audited by actor, task,
  provider, model, token counts and outcome.
- **Cost control.** Per-user rate limits and a monthly token budget per site (warning at 80%, refused at 100%).
- **Accessible UI.** Works without JavaScript (form → result page). With JavaScript, announce only short status
  messages ("Working on it…", "Suggestion ready") and move focus to the suggestion; never stream text into a live
  region.

## Consequences

- Sites that never turn the assistant on never contact a provider.
- Each provider adapter must be tested without live calls (mocked HTTP), and every task needs tests with hostile
  input.
- Prompt injection can still produce bad suggestions, but not actions; a person always decides.
