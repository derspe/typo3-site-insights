# Page details: devices, events and goals

Date: 2026-10-30
Status: approved by the user on 2026-10-30

## Intent

An editor who opens the page details of a page wants three answers from Matomo that the
modal does not give yet:

1. On which devices is the page viewed – and is that different from the whole site?
2. Does the page trigger events (e.g. the Amazon clicks on smart-home-insights)?
3. Does the page contribute to goals (e.g. the contact form on the Analog site) – as the
   entry page of a converting visit, and as a page viewed on the way to the conversion?

## Placement and look (decided with mockup "B")

- One row with three tiles in the page details modal (`<si-page-detail>`,
  `Resources/Public/JavaScript/page-detail.js`), **between the pageviews chart and the
  search queries**. The existing order stays untouched: head, transitions, pageviews chart,
  *new tiles*, search queries.
- Tiles show the key figure at a glance; details open on demand (expand) **full width
  below the tile row**.
- Narrow screens: the tiles stack (builder's call on the breakpoint).

### Devices tile

- A stacked horizontal bar with the shares of mobile, desktop and tablet for this page.
- Below it a thinner bar with the same shares for the whole site, for comparison.
- Percentages as text next to/below the bars.
- Other Matomo device types (TV, console, car, …) are summed up as "Other" (my call).
- No expand.

### Events tile

- Key figure: number of events on the page in the period; below it events per 100
  pageviews and the change against the compare period (when a compare period is set).
- A small chart of events over time, in the same granularity as the pageviews chart
  (per day, per week above 90 days – `TransitionsService::WEEKLY_FROM`) (my call).
- Expanded: a tree **category → action → name**, every level collapsible on its own, with
  counts per node. Sorted by count.

### Goals tile

- Two key figures: conversions **as entry page** and conversions where the page was viewed
  **on the way** (before the conversion, not as entry). The user asked to show both,
  separately.
- Expanded: a table with one row per goal and the two columns "As entry" / "On the way".
- Changes against the compare period like the other figures.

### Comparison per tile (my calls)

- Devices: page shares of the compare period as change in percentage points next to each
  share (one extra report: page segment, compare range). The site bar gets no comparison.
- Events: key figure and events per 100 pageviews with change; the small chart overlays
  the compare period like the pageviews chart. Tree nodes show no changes.
- Goals: both key figures and every table row with change; the figures come from the
  engagement call that already runs for the compare range, so this costs nothing extra.

### Server-built segments and their cost (my calls, see the last section)

- The events tree (names per category) is fetched only when the tile is expanded, as a
  second request – it needs the categories first anyway and avoids one segment archive per
  category for every page someone merely opens.
- Segments are built only by the backend from the validated page URL and the fixed
  dimensions `pageUrl`, `eventUrl`, `eventCategory` (category values taken from Matomo's
  own response), combined with the user's segment if it is one defined in Matomo. Users
  still cannot send free-form segments; the rule in `MatomoDashboardService` stays.
- If Matomo does not process segments on request (browser archiving for segments switched
  off), segmented reports come back empty without an error. Detection: the page has
  visits in the unsegmented page report (`engagement.entries`/`pageviews` > 0) but the
  devices report with the page segment has 0 visits → segment-based tiles (devices,
  events) show "not available in this Matomo" instead of zeros. Goals are unaffected (no
  segment).

### Common behaviour

- All three tiles follow the period, segment and compare period chosen in the modal head,
  like transitions and the pageviews chart (my call).
- Empty states: no events on the page → short hint in the tile; no goals defined in
  Matomo → short hint; Matomo not configured → the existing `transitions.notConfigured`
  wording. Never an empty box.
- Labels in English and German (`locallang_js.xlf`, `de.locallang_js.xlf`); no notes on
  caching (removed from the transitions hint on purpose).

## Data from Matomo (verified on 2026-10-30 against the live smart-home-insights instance, Matomo 5.12.0)

| Tile | API | Notes |
|---|---|---|
| Devices, page | `DevicesDetection.getType` with segment `pageUrl==<url>` (combined with the user's segment, if any) | visits that viewed the page – correct for this question |
| Devices, site | `DevicesDetection.getType` without the page segment | same period / user segment |
| Events | `Events.getCategory` (`secondaryDimension=eventAction`, `expanded=1`) with segment **`eventUrl==<url>`**; names per category via `Events.getAction` (`secondaryDimension=eventName`, `expanded=1`) with segment `eventUrl==<url>;eventCategory==<category>`, one call per category in the same bulk request | **not** `pageUrl` – see findings |
| Event series | `Events.getCategory` with `period=day` or `week` over the range and segment `eventUrl==<url>`, totals of `nb_events` per period | |
| Goals | the `goals` column of `Actions.getPageUrl` – already returned by the call `TransitionsService::engagement` makes today (no `idGoal` needed); goal names from `Goals.getGoals` (already used by the dashboard) | |

Findings from the live instance:

- **`pageUrl==<url>` selects visits, not actions.** On Events reports it counts every event
  of a visit that viewed the page, also events fired on other pages. Proof:
  `/gadgets/fyta` had 0 events on the page (raw visit log) but 1 with the `pageUrl` segment.
- **`eventUrl==<url>`** (Matomo segment of the Events plugin, the page an event was fired
  on) matched the raw visit log exactly on three test pages (1/1, 0/0, 1/1, including the
  fyta case). Residual risk: no visit in the data fired events on two different pages, so
  it is unproven whether `eventUrl` also selects whole visits. Acceptable; mention it in a
  code comment.
- Device labels come translated ("Smartphone", "Peripheriegeräte"); map by the row's
  `segment` value (`deviceType==smartphone`), not by label. Mobile = smartphone, phablet,
  feature phone; desktop; tablet; everything else "Other" (my call).
- Goal metrics per goal row: `nb_conversions_entry` = conversions of visits that entered on
  the page; `nb_conversions_page_uniq` = conversions of visits that viewed the page before
  converting (entries included). "On the way" = `nb_conversions_page_uniq −
  nb_conversions_entry`, floored at 0 (my call, keeps the two figures disjoint as asked).
  On smart-home-insights both are often equal (entry = whole visit), so "on the way" is
  mostly 0 there – expected, not a bug.
- On smart-home-insights the goals are outlink goals (Amazon, Awin) and partly overlap
  with the Amazon events. Both tiles are shown anyway; that is how the site is set up.
- Timing: one-month range with segments 1.6 s (bulk of six reports). One-year range,
  three segmented reports: 11.2 s on first request, 0.5 s afterwards (Matomo archives the
  segment). The tiles therefore must load independently and show a loading state; cache
  results via `ApiCache` like the transitions.

The URL handling (`TransitionsService::matomoUrl`) and the caching via `ApiCache` are reused.

## Backend shape (builder's call within these lines)

- New endpoint(s) next to `matomo/transitions` and `matomo/page-series` in
  `ApiController::dispatch`, added to `AccessPolicy` like those; parameters and validation
  via the existing `MatomoDashboardService::pageQuery`.
- Calls bundled with `MatomoClient::bulk`; normalizing in `MatomoNormalizer` like the
  existing page reports; unit tests in the style of `MatomoNormalizerTest` /
  `TransitionsServiceTest` with `FakeHttp`.
- One endpoint per tile or one combined endpoint – builder's call; tiles must load
  independently so one slow report does not block the others.

## Goals, non-goals, anti-goals

- Goals: the three answers above, per page, with comparison.
- Non-goals: revenue, ecommerce items, device breakdown of events/goals, other services
  (PageSpeed, Bing, Google Business – deferred or rejected), Search Console vs. Matomo
  comparison (dropped by the user).
- Anti-goals: changing the order or look of the existing modal sections; figures that look
  exact but count something else (e.g. events of the whole visit labelled as events of the
  page).
