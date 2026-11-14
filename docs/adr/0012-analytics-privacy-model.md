# 0012 — Analytics privacy model

- Status: Accepted
- Date: 2026-11-13

## Context

Site owners want to know how many people visit, which pages they read and where they come from. Most analytics
tools use cookies, scripts, fingerprinting and third parties, which need consent banners (a burden for everyone and
often an accessibility barrier) and send visitors' data elsewhere. SightPress sites are built for people who should
not have to pay for content with their privacy.

## Decision

- Build analytics as an optional module (`analytics`) that is on by default for new sites.
- Count on the server, first party only: **no cookies, no JavaScript, no fingerprinting, no third parties.**
- **Never store IP addresses, full user agents or query strings.**
- Count unique visitors per day only: a visitor key is an HMAC of the site, IP address and user agent with a random
  salt that is unique per site and per day. Delete the salt when the day is rolled up, so keys can't be reversed or
  linked across days. There is no "returning visitors" metric, by design.
- Respect `Sec-GPC: 1` and `DNT: 1` by not counting the request. Don't count signed-in users of the site, admin
  pages, assets, `HEAD` requests or prefetches.
- Count bots and AI crawlers separately from visitors, by name and kind.
- Keep only coarse dimensions: page, referrer host (not full URL), page locale, device class, and site search
  queries with obvious personal data (email addresses, phone numbers, long numbers) dropped.
- Keep raw hits only for the current day. Roll finished days into daily aggregates, then delete the raw rows and
  the salt.
- Keep aggregates for 25 months by default; a site admin can set 1 to 25 months.
- Recording runs after the response is sent and can never change or break the response.
- Show the data in accessible reports: every chart has a text summary and a data table.

## Consequences

- Sites can run analytics with no consent banner in most jurisdictions, but this is not legal advice; the Privacy
  Policy template explains the analytics in plain language while the module is on.
- Numbers are less detailed than tracking-based tools (no sessions, no journeys, no returning visitors).
- Tests must prove that no table holds an IP address, a user agent or a query string, using canary values.
