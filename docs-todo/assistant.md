# AI assistant (A-2, A-3)

The AI assistant gives suggestions from an AI service the site chooses, with its own API key ("bring your own key"):
summaries, descriptions for search engines, alt text, drafts and answers to questions. It is an optional feature,
**off** for new sites. Nothing is sent anywhere until an admin chooses a service, enters its key and someone asks
for a suggestion; every suggestion is checked by a person before it is used. ADR-0010 (what it may do), ADR-0040
(how it is built).

## Services

| Id | Service | Models | Notes |
| --- | --- | --- | --- |
| `anthropic` | Anthropic (Claude) | Claude Opus 5.5, Sonnet 5.5, Haiku 5.5, Fable 5.2 | all can describe pictures |
| `openai` | OpenAI (GPT) | GPT-5, GPT-5 mini | |
| `mistral` | Mistral AI | Large, Medium, Small | processed in the EU; Large can't describe pictures |
| `openai_compatible` | any service or own server with OpenAI's API | any (typed) | needs `--base-url`; no pictures |

The list is in `resources/assistant/providers.php`. Modules can add services and tasks
(`ModuleRegistrar::assistant()`).

## Set it up from the console

```bash
bin/sightpress site:module:enable <site> assistant
printf %s "$KEY" | bin/sightpress site:assistant:set <site> --provider=anthropic --model=claude-haiku-5-5 --key-stdin
bin/sightpress site:assistant:test <site>
bin/sightpress assistant:ask <site> "How do I add a page to the main menu?"
bin/sightpress site:assistant:usage <site> [--month=2026-11]
bin/sightpress site:assistant:disable <site> [--remove-key]
```

The key is read from standard input so it never appears in the shell history. It is stored encrypted with
`APP_SECRET`; afterwards only its last four characters are shown (`••••1234`). Without `--key-stdin` the current key is
kept.

## Organisation settings (`config/tenant.php`)

```php
'assistant' => [
    'provider' => 'mistral',            // a default every site inherits (optional)
    'model' => 'mistral-small-latest',
    'base_url' => null,                 // for openai_compatible
    'site_keys' => true,                // false: sites can't choose their own service or key
    'allow_private_hosts' => false,     // true: allow a model server on the local network
    'monthly_budget' => 0,              // tokens a site may use each month by default; 0 = no limit
],
```

The default's key comes from the environment variable `ASSISTANT_API_KEY`, never from a file. A site that chooses
the same service inherits that key unless it enters its own.

## Limits and privacy

- Who: people with **Use the AI assistant** (admins, editors and contributors at first) whose role is one of the
  site's assistant roles; admins always.
- How often: 20 requests per person every 10 minutes.
- How much: a monthly token budget per site (UTC month). From 80 % people are warned; at 100 % the assistant refuses
  until the 1st of the next month or a bigger budget.
- Sent: only what the person asks and the text they choose to share (the page they are on). Never user lists, email
  addresses, the audit log or other pages.
- Kept: one row per use (when, who, task, service, model, tokens, outcome) in `assistant_usage`, and an audit log
  entry with the same facts. Never the question, the page text or the answer, and no IP address.
- Addresses: HTTPS only (HTTP only in `dev`); private and local network addresses are refused unless the
  organisation allows them.

## What goes wrong, in plain words

Wrong key, no credit left, too many requests, too slow, model no longer offered, the service declined, an unusable
answer, or the service can't be reached: each has its own message saying what to do. The console prints the same
messages.

## The AI assistant screen (A-3)

**Site settings → AI assistant** (`/admin/assistant`, admins). Turn the assistant on first on the **Features** screen.

1. **Status** says whether it is ready, or what is missing.
2. **AI service**: choose a service (each one says what it is), its model, and paste the API key into **New API key**.
   Only its last four characters are shown afterwards; **Remove key** deletes it. A service without a model list (an
   OpenAI-compatible server) needs the model's name and the service's address.
3. **Test connection** sends a tiny request and says whether it worked.
4. **Who may use it, and how much**: tick the roles (admins always may) and set a monthly budget in tokens (empty =
   the organisation's; 0 = no limit). This month's use is shown above.
5. **What is sent, and what is kept** explains the privacy side in plain words.

## Asking the assistant (A-3)

- **Dashboard and page editor**: "Ask the assistant" has a question box. On the page editor, tick **Share this page
  with the assistant** to let it read the saved page.
- **Suggest buttons** under *Summary*, *Title in search results* and *Description for search engines* ask for a
  suggestion for that field, from the saved page (save first).
- The suggestion appears under its own heading. **Insert** puts it in the field (not saved yet), **Copy** copies it,
  **Try again** asks again, **Discard** removes it. Without JavaScript the same buttons are on a page of their own.
