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

**Page module**

- Key figures of the current page above the content: Matomo pageviews and entries,
  Search Console clicks, impressions and average position of the last 28 days,
  compared with the 28 days before; only configured sources are shown
- Collapsible box with the top 10 search queries of the page
- Search Console rows are matched by path, not by host, so the figures also appear in
  development environments with another domain
- Shown to users with access to the Site Insights module; data is loaded in the
  background and cached (Matomo 1 hour, Search Console 6 hours)

**News records** (EXT:news)

- Folders with news show the key figures of all their records above the page module
  and the list module: Matomo pageviews and entries, Search Console clicks,
  impressions and average position of the last 28 days, compared with the 28 days
  before; the totals are always visible, the list of records can be expanded
- Sort by any figure or by its change against the period before (largest loss first),
  search by title, filter news without impressions; a click on a row shows its
  search queries
- Set the news detail page per site in the settings; the URLs come from the route
  enhancers of the site. All records of a folder are loaded with one Search Console
  query and one Matomo report per period

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

The package is not on Packagist yet. Add the GitHub repository to the `repositories`
section of your project `composer.json`:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/derspe/typo3-site-insights" }
    ]
}
```

Then install it:

```bash
composer require derspe/site-insights:dev-main
vendor/bin/typo3 extension:setup
```

Open **Sites → Site Insights**. Each site configuration has its own settings.

### Data sources

Customers often use only one of the two services. In **Admin Tools → Settings →
Extension Configuration → derspe_site_insights** choose the data sources: Matomo and
Search Console (default), Search Console only or Matomo only. A disabled source
disappears from the module, the page module and the API right away. Its dashboard
widgets disappear after flushing all caches, because TYPO3 only rebuilds the service
container then.

## Security

- Only system maintainers see and change the settings (Matomo access, service account
  key, backfill). Other administrators may run snapshots and force re-inspections. All
  other users need access to the module and only see sites whose root page is inside
  their DB mounts. Note that in the Development context TYPO3 treats every
  administrator as system maintainer.
- Secrets (Matomo token, Google service account key, cached access tokens) are
  encrypted with a key derived from the instance `encryptionKey` and never sent to the
  browser. Changing the Matomo host removes the stored token.
- There is no public endpoint. Google is accessed with a service account and the
  read-only scope; access tokens are always requested from Google's fixed token
  endpoint, whatever the key file says.
- URL Inspection calls are limited to 1,800 per site and day (Google allows 2,000).
- Child sitemaps are only fetched on the host of the configured sitemap; responses are
  limited in size and number.

### Matomo

Enter the Matomo URL, an auth token (read access is sufficient) and the site ID.
Afterwards the goals of the site can be selected. All API calls are sent as POST,
so Matomo's option "only accept POST requests with token_auth" can stay enabled.

### Google Search Console

The extension reads Search Console data with a Google **service account**. There is
no consent popup and no redirect URI, so the same setup works in every environment.
The settings tab contains the same guide with direct links.

1. In the [Google Cloud Console](https://console.cloud.google.com/projectcreate)
   create a project (or choose an existing one).
2. [Enable the Google Search Console API](https://console.cloud.google.com/apis/library/searchconsole.googleapis.com)
   for this project.
3. [Create a service account](https://console.cloud.google.com/iam-admin/serviceaccounts/create).
   It needs no roles and no user access.
4. Open the service account, tab *Keys* → *Add key* → *Create new key* → JSON.
5. Choose the downloaded file in the settings (or paste its content), enter the
   property – `sc-domain:example.com` (domain property) or the URL prefix
   (`https://www.example.com/`) – and save.
6. In Search Console open *Settings* → *Users and permissions* of the property and add
   the e-mail address of the service account (shown in the settings) with the
   permission *Full*. The extension only requests the read-only scope
   `webmasters.readonly`, so it cannot change anything.
7. Click **Check again**: the status turns green as soon as the service account can
   see the property.

In Google Workspace organizations the policy `iam.disableServiceAccountKeyCreation`
can block creating keys; an administrator of the organization has to allow it for
the project. Treat the key file like a password and delete it after saving.

### Scheduler

| Command | Recommended | Purpose |
|---|---|---|
| `site-insights:gsc-snapshot` | daily | Stores the top queries/pages of the day (Search Console data is complete after ~3 days). Fills missing days of the last 7 days (`--days`). |
| `site-insights:run-jobs` | every 5 minutes | Continues background jobs started in the module (index scan, backfill) when nobody keeps the module open. |

Add both as *Execute console commands* tasks. The tracker and the visibility
index need snapshots; use **Settings → Snapshot backfill** once to fetch up to 90
days immediately.

API responses are cached in the database tables `cache_derspe_site_insights` and
`cache_derspe_site_insights_tags`. TYPO3 does not delete expired entries on its
own: add the task *Caching framework garbage collection* (e.g. daily) and select
the backend `Typo3DatabaseBackend`, otherwise the tables keep growing.

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
