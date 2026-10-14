# Site Insights for TYPO3

Google Search Console and Matomo reports inside the TYPO3 backend – as a backend
module and as dashboard widgets. Everything runs in PHP: no Node.js, no external
service, no long-running processes. It works on ordinary shared hosting.

## Features

**Google Search Console**

- Performance: clicks, impressions, CTR, average position – with comparison to the
  previous period or the previous year, search type (web, Discover, News, image,
  video) and the latest day with data as anchor
- Clicks & impressions chart, search appearance (rich result types)
- Top queries and top pages, optionally joined with Matomo pageviews
- Brand vs. non-brand split (configurable brand terms)
- Cannibalization: queries for which several pages rank
- Drilldown: query × page with country and device filter
- Tracker: daily snapshots of the top queries/pages, history per query or page
  (clicks, impressions, position, visibility)
- Own visibility index: Σ impressions × CTR weight of the position
- Indexing: URL Inspection for single URLs and for all URLs of the sitemap
  (plus internal links of configurable source pages), incl. structured data verdict

**Matomo**

- Visits, pageviews, bounce rate, visit duration – with comparison
- Segments, goals (selectable) with conversions and chart
- Visits over time, most viewed pages, traffic sources

**Dashboard widgets** (group "Site Insights")

- Search Console: key figures, clicks & impressions, top queries, visibility index
- Matomo: key figures, visits over time

The UI follows the TYPO3 backend color scheme (light/dark) and is translated into
English and German.

## Requirements

- TYPO3 14.3 LTS (`typo3/cms-backend`, `typo3/cms-dashboard`)
- PHP 8.2+ with `sodium` (used by the TYPO3 core for encrypting secrets)
- `typo3/cms-scheduler` is recommended for the daily snapshot

## Installation

```bash
composer require derspe/site-insights
vendor/bin/typo3 extension:setup
```

Open **Sites → Site Insights**. Each site configuration has its own settings.

## Security

- Only administrators can see and change settings, connect Google, run snapshots and
  backfills or force re-inspections. Other users need access to the module and only see
  sites whose root page is inside their DB mounts.
- Secrets (Matomo token, OAuth client secret, refresh token, cached access tokens) are
  encrypted with a key derived from the instance `encryptionKey` and never sent to the
  browser. Changing the Matomo host removes the stored token.
- There is no public endpoint: the OAuth result is completed through the CSRF protected
  backend API; the state is single use, expires after 10 minutes and is bound to the
  site and the administrator who started the connection.
- URL Inspection calls are limited to 1,800 per site and day (Google allows 2,000).
- Child sitemaps are only fetched on the host of the configured sitemap; responses are
  limited in size and number.

### Matomo

Enter the Matomo URL, an auth token (read access is sufficient) and the site ID.
Afterwards the goals of the site can be selected. All API calls are sent as POST,
so Matomo's option "only accept POST requests with token_auth" can stay enabled.

### Google Search Console

1. In the Google Cloud Console create an OAuth client of type **Web application**
   and enable the *Google Search Console API*.
2. Add the redirect URI shown in the settings as authorized redirect URI. It points
   to a static page of the extension (`/_assets/<hash>/OAuth/callback.html`), which
   hands the result to the settings window; the connection is then completed through
   the authenticated backend API. The path depends on the installation path of the
   extension, so register the URI shown by each environment.
3. Enter client ID and secret, save, then click **Connect with Google**.
4. Enter the property: `sc-domain:example.com` (domain property) or the URL
   prefix (`https://www.example.com/`).

Only the read-only scope `webmasters.readonly` is requested.

### Scheduler

| Command | Recommended | Purpose |
|---|---|---|
| `site-insights:gsc-snapshot` | daily | Stores the top queries/pages of the day (Search Console data is complete after ~3 days). Fills missing days of the last 7 days (`--days`). |
| `site-insights:run-jobs` | every 5 minutes | Continues background jobs started in the module (index scan, backfill) when nobody keeps the module open. |

Add both as *Execute console commands* tasks. The tracker and the visibility
index need snapshots; use **Settings → Snapshot backfill** once to fetch up to 90
days immediately.

## How long running work is handled

Shared hosting allows neither background processes nor long requests. Index scans
and backfills are stored as jobs in the database and processed in time-boxed steps
(a few seconds each). The open module drives the steps while it shows the
progress; the `site-insights:run-jobs` command finishes them otherwise. The URL
Inspection API allows 2,000 requests per day and property – a scan stops at 1,800
and inspected URLs are cached for 7 days.

## Development

```bash
composer install
vendor/bin/phpunit
```

The UI consists of plain ES modules with Lit and Chart.js as shipped by the TYPO3
core and the dashboard extension – there is no build step.

## License

GPL-2.0-or-later
