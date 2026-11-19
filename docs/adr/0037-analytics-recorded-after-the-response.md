# 0037 — Analytics: recorded after the response, rolled up daily in the site database

- Status: Accepted
- Date: 2026-11-19

## Context

ADR-0012 set the analytics privacy model: count on the server, no cookies or scripts, no IP addresses, user agents
or query strings kept, unique visitors per day with a salt that is deleted, raw hits only for the current day. AN-1
builds it. Some choices weren't settled there: how a module records requests when the module contract has no way
to add middleware, how it knows which page a response showed, how a recording failure stays away from the
visitor, and how to aggregate portably when MySQL's default collation compares text ignoring case and accents.

## Decision

- **Tell modules about each request after the response.** Core adds `RequestAnsweredMiddleware` to the HTTP
  stack (after the visitor is known). It puts an `Http\Contract\AnsweredResource` on the request and, through the
  new `Http\AfterResponse` queue, dispatches `Http\Contract\RequestAnswered` (request, status, resource, signed in,
  time) on the site's event dispatcher **after the response has been sent**: `Kernel::run()` emits the response,
  calls `fastcgi_finish_request()` where it exists and then `Kernel::terminate()`. A thrown "not found" is told as a
  404. Work that fails after the response is logged (class and message) and never reaches the visitor. Any module can
  listen; there is no analytics-only back door. Tests (and other callers of `Kernel::handle()`) call `terminate()`.
- **Handlers say what they showed.** The page module calls `AnsweredResource::page(id, locale, markdown)` for pages
  (HTML or `.md`), the SEO module `file('sitemap' | 'robots' | 'llms' | 'llms_full')`. Analytics doesn't parse
  paths to find pages, so moved pages keep their numbers.
- **Search queries wait for their request.** `SearchPerformed` carries no request, so the analytics module keeps
  each search (already without personal data) until `RequestAnswered` for the same request, and records it only
  when that request counts (no `Sec-GPC` / `DNT`, not signed in, not a bot). Searches whose text looks like an email
  address, a phone number or a number of six digits or more are counted, but their text is not kept.
- **Tables** (analytics module migration, Schema API only): `analytics_hit` (today's raw rows with the day in the
  site's time zone), `analytics_daily` (day, metric, dimension, item, amount) and `analytics_salt` (one random
  salt per day). Columns avoid reserved words (`day_on`, `amount`, `search_query`, `result_count`).
  `analytics_daily` has **no unique key** on (day, metric, dimension, item), unlike plan §1.2: on MySQL's default
  collation `/About` and `/about`, or `café` and `cafe`, would collide. The roll-up keeps one row per figure itself
  (delete and insert of the whole day in one transaction).
- **Aggregate in PHP**, not with `GROUP BY`, for the same reason. The roll-up reads one day's raw rows at a time.
  Reports read the daily figures for the range plus the raw rows of days not rolled up yet (today is live), and sum
  them in PHP.
- **Roll up once an hour** from `schedule:run` (`analytics.rolled_up_at` in the site settings): finished days
  become figures, their raw rows and salt are deleted, then figures older than the retention go. A raw hit that
  arrives after its day was rolled up (a request answered just after midnight) is added to the day's figures, never
  replacing them. `site:analytics:report` warns when the last roll-up is more than two hours old.
- **Visitor key** = the first 32 hex characters of HMAC-SHA256(day's salt, site id + IP + user agent).
- **Retention** is a site setting (`analytics.retention_months`, 1–25, default 25) changed through
  `AnalyticsRetention`, which dispatches `SettingsChanged`, so the audit log records it. It is not a generic
  registered setting, because the Site settings screen can't check a range; the Analytics screen (AN-3) changes it.
- **Not counted:** methods other than `GET`; prefetch, prerender and previews; `Sec-GPC: 1`, `DNT: 1`; people signed
  in to the site; `/admin`, `/assets/`, `/media/` and `/_…`; "not found" for asset file endings and what
  vulnerability scanners try (`.php`, `.env` …); redirects, 304 and other statuses. Bots are recorded by name, kind
  and resource only, never as visitors. People reading the sitemap, `robots.txt` or `llms.txt` aren't counted.
- **The Privacy notice starter page** gets a "Visitor statistics" section (en-GB, pt-PT, es) when analytics is on
  as the site is created; the text says 25 months at most.

## Consequences

- The response time doesn't include recording where PHP has `fastcgi_finish_request()` (PHP-FPM). Elsewhere
  (`php -S`, for example) the client waits for the recording too; it is one or two small queries.
- Every request to a site builds one small event; sites with analytics off pay only that.
- A page view in Markdown counts as a page view of that page.
- Figures of a day stay correct whatever the database collation, at the cost of reading rows into PHP; a report
  over 25 months of a large site reads many rows (H-2 measures it).
- Changing the site's time zone later moves where new days start; days already rolled up keep their dates.
- `theme-interface` and `module-interface` are unchanged. `Http\Contract` is a new core contract namespace.
