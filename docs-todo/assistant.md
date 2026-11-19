# AI assistant (A-2 … A-6)

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

## What it can draft (A-4)

| Where | Button | What you get |
| --- | --- | --- |
| Page editor, under *Summary* | Suggest a summary | one or two sentences, at most 300 characters |
| Page editor, under *Title in search results* / *Description for search engines* | Suggest a title… / Suggest a description | a title of about 60 characters, a description of about 160 |
| Every panel | Rewrite text | the text you paste, simpler, shorter, friendlier or more formal (rich text HTML stays HTML) |
| Page editor panel | Draft this page | headings and text from what you ask for; **Add to the end of the page** puts them in the editor, unsaved |
| Page editor, next to each publish check | Explain this | what the check means and how to fix it |
| A picture in the media library, under each alt text field | Suggest alt text | a description of the picture (only with a model that can see pictures) |

Everything is checked before you see it: too long, the wrong form, unknown blocks or unsafe HTML are refused or
cleaned, and the page's own text can't give the assistant instructions.

**Alt text from the assistant has to be checked by a person.** After you save it, the picture says "The AI assistant
suggested this alt text", and pages that show it can't be published until someone ticks **I checked the alt text**
(or changes the text) and saves.

The instructions the assistant gets are in `resources/assistant/prompts/`. A translation can go in a folder named
after the language (`pt-PT/summary.md` or `pt/summary.md`).

## Help with SightPress (A-5)

A question in **Ask the assistant** (without *Share this page*) is answered from the SightPress documentation: the
assistant gets the parts of the documentation that match the question and that you can use, and its answer ends
with a link to read more. It never points you to screens your role can't open (a contributor isn't sent to
*Features*); it says an admin can do it instead.

For maintainers: the documentation is copied into `resources/assistant/help-index.json` by
`php tools/assistant-help-index.php <docs>/docs` (add `--check` to see whether it is up to date). The *Help index*
workflow does it whenever the release pull request changes and opens a pull request with the new file.

## Draft a translation (A-6)

On a page written in more than one language, the **Translations** section has **Draft a translation into <language>
with the AI assistant** for each language the page isn't written in yet. The assistant translates the words only:
the blocks, pictures and links stay as they are. The new translation opens in the editor as a draft, with a notice:
"The AI assistant drafted this translation, and nobody has checked it yet."

It can't be published until a person has read it: either save it from the editor (after changing what needs
changing) or choose **I checked this translation**. Until then the page list and the page's versions say
"drafted by the AI assistant, not checked yet". Very long pages (over 60,000 characters) are refused: start them with
**Translate** and use **Rewrite text** for parts.
