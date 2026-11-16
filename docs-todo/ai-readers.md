# AI readers: Markdown pages, llms.txt and the AI crawler policy (C-6.8)

## What it does

AI search engines and assistants read text best. Every public page is offered to them as clean Markdown, and the
site says which AI services may read it.

- **Markdown version of every page**: add `.md` to its address (`/about/team.md`; `/index.md` for the home page,
  `/pt-pt/index.md` for its Portuguese version). A request for the page that prefers `text/markdown` in its
  `Accept` header gets the same Markdown. The document has the title as a heading, the summary as a quote, every
  block (headings, text, lists, links, pictures with their alt text, videos and audio with their transcripts, files
  with their type and size; forms are left out) and, at the end, the page's address and when it last changed.
  All links and pictures are absolute.
- The Markdown answer carries the HTML page's canonical address in a `Link` header and `Vary: Accept`; a page
  that is left out of search says `noindex` there too. Members-only pages are only shown to signed-in people,
  as Markdown too.
- Pages search engines may index have `<link rel="alternate" type="text/markdown">` in their `<head>`.
- **`/llms.txt`** (the llmstxt.org format): the site's name, a one-line summary (the home page's description or
  summary), then a link to the Markdown version of every page search engines may index, with its description,
  grouped by language on sites with more than one; and a link to the full text.
- **`/llms-full.txt`**: the same pages in full, as Markdown. It stops before 500 KB and says how many pages were
  left out and where to find them.
- Only published, public, indexable pages are ever listed (members-only and `noindex` pages never are; members-only
  rules for these files arrive with members-only sites in M2).

## The AI crawler policy

Site setting **AI crawlers** (`seo.ai_crawlers`; the admin screen comes with C-7.3):

| Choice | What robots.txt says |
| --- | --- |
| **Allow all AI crawlers** (`allow_all`, the default) | Nothing extra: every crawler may read public pages |
| **Allow AI search and assistants, block AI training** (`search_only`) | Crawlers that train AI models (GPTBot, ClaudeBot, Google-Extended, CCBot …) are told `Disallow: /` |
| **Block all AI crawlers** (`block_all`) | Those, and AI search and assistant crawlers (OAI-SearchBot, ChatGPT-User, Claude-SearchBot, PerplexityBot …) |

Search engines are never affected. The lists are in `resources/seo/ai-crawlers.php`. robots.txt is a request
that well-behaved crawlers follow, not a lock.

## For developers

- Page module: the public page handler serves `.md` and `Accept: text/markdown`; new contracts `MarkdownPages`
  (one page as a Markdown document) and `MarkdownAddress`; `index` is a reserved page slug.
- SEO module: `AiCrawlerPolicy`, `SeoFiles::llms()` and `llmsFull()` (cached with the sitemap), routes
  `/llms.txt` and `/llms-full.txt`, the setting registered through the settings registrar.
- Tests: `tests/Unit/Page/PageMarkdownTest.php` (golden file `tests/Unit/Page/golden/every-block.md`: a page with
  every block type), `tests/Unit/Seo/SeoFilesTest.php`, `tests/Integration/Seo/AiReadersTest.php`,
  `tests/A11y/starter.spec.ts`.
