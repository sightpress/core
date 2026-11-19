# Analytics (AN-1)

Analytics counts how many people visit a site, what they read, where they come from, what they search for, which
addresses aren't found, and which search engines and AI crawlers read it. It is an optional feature, on by default
for new sites; admins turn it off or on on the **Features** screen or with `site:module:disable|enable <site>
analytics`. ADR-0012 (privacy model), ADR-0037 (how it is built).

## Privacy

- Counted on the site's own server. **No cookies, no JavaScript, no fingerprinting, no third parties.**
- **Never stored:** IP addresses, user agents, query strings, full referrer addresses.
- A visitor is counted once per day with a code made from the IP address and browser with a random value of the day
  (the "salt"). When the day is over, the raw rows and the salt are deleted, so the codes can't be traced back to
  anyone or linked from one day to the next. There is no "returning visitors" number, by design.
- Visitors over several days are the sum of each day's visitors.
- The **Privacy notice** written for new sites has a "Visitor statistics" section while analytics is on (in British
  English, European Portuguese and Spanish). Sites that turn analytics off later should remove it from the page.

## What is counted

- Pages read by people (HTML, and the Markdown version of a page), with the language of the page, the kind of
  device (phone, tablet or computer) and where the visit came from:
  - **Direct** (no referrer, or a link on the site itself);
  - a **search engine** or an **AI assistant** by name (`resources/analytics/search-engines.php`);
  - **another site**, by host name only, without `www.`;
  - a **campaign** from `utm_source` or `ref` in the address (lower case, letters, digits, spaces, `.`, `_`, `-`,
    50 characters at most).
- Addresses that don't exist ("pages not found"): the path only, 300 characters at most.
- Site searches (when search is on): the text in lower case, 100 characters at most, and the number of results.
  Searches that look like an email address, a phone number or a number of six digits or more are counted, but their
  text is not kept.
- Bots, by name and kind (search engine, AI search, AI training, other) and what they read (page, Markdown,
  sitemap, robots.txt, llms.txt, llms-full.txt). Bots never count as visitors. The list is
  `resources/analytics/bots.php`, which takes the AI crawlers from `resources/seo/ai-crawlers.php`.

## What is not counted

- Requests from browsers that send **Global Privacy Control** (`Sec-GPC: 1`) or **Do Not Track** (`DNT: 1`).
- People signed in to the site.
- The admin, assets, media files, `HEAD` requests, prefetches and prerenders, redirects.
- "Not found" for files such as icons, scripts and images, and addresses that vulnerability scanners try (`.php`,
  `.env` …).

## Days, roll-up and retention

- Days run from midnight to midnight in the site's main time zone, so the numbers are the same for everyone.
- `schedule:run` rolls up finished days once an hour: their raw rows become daily figures and are deleted with the
  day's salt. Today's numbers are live.
- Daily figures are kept for **25 months** by default (two full years to compare); a site can keep them for 1 to 25
  months (`analytics.retention_months`; the Analytics screen changes it, and each change is in the audit log).

## Commands

- `site:analytics:report <site> [--from=YYYY-MM-DD] [--to=YYYY-MM-DD] [--report=<name>|all] [--format=table|csv|json]`:
  the last 30 days by default. Reports: `summary` (visitors, views, views per visitor, each with the change compared
  with the previous period of the same length), `daily`, `pages`, `sources`, `languages`, `devices`, `searches`,
  `searches-without-results`, `not-found`, `bots`. CSV is UTF-8 with a header row; text that a spreadsheet would run
  as a formula starts with `'`. A warning (on standard error) says when the last roll-up is more than two hours old,
  which means `schedule:run` isn't running.
- `site:analytics:purge <site> [--before=YYYY-MM-DD]`: deletes the figures from before a day; without `--before`,
  what is older than the site's retention.

## Permissions

- `analytics.view` (admins and editors): see the reports.
- `analytics.manage` (admins): change how long analytics are kept, and delete them.

## For module authors

Every request to a site is told to the site's listeners of `SightPress\Core\Http\Contract\RequestAnswered` after the
response has been sent. A handler says what it showed with `AnsweredResource::fromRequest($request)?->page($id,
$locale)` or `->file('<kind>')`.

# The Analytics screen and the dashboard (AN-3)

- **Analytics** in the admin menu (`/admin/analytics`), for admins and editors (`analytics.view`). Ranges: **Last 7
  days**, **Last 30 days** (default), **Last 90 days**, **Last 12 months**, or **Other days** (two dates). The days
  are in the site's time zone, which the screen names.
- Headline sentences (visitors, page views, page views per visitor, each compared with the days before), then one
  section per report: visitors over time (chart, summary, "Show the data for each day"), pages read most, where
  visitors came from, languages (sites in several languages), devices, site search (while it is on), pages not found
  (with **Add a redirect**, which fills in the Redirects form), search engines and AI crawlers.
- Every report downloads as a CSV file with every row (`/admin/analytics/download?report=<name>` plus the range);
  its values are the same as `site:analytics:report --format=json`.
- Admins (`analytics.manage`): **Months to keep analytics for** (1–25) and **Delete analytics data** (with a
  confirmation page; audited).
- The dashboard shows **Last 7 days** to people with `analytics.view`: one sentence and **See all analytics**.
- Module authors: dashboard widgets registered with `dashboard()->add()` now appear on the dashboard (ADR-0039).

Non-dev how-to: `docs/how-to/see-who-visits-your-website.md` in the docs repo.
