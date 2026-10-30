# Page details: devices, events and goals – Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Three Matomo tiles (devices, events, goals) in the page details modal between the pageviews chart and the search queries.

**Architecture:** A new `PageMatomoService` builds page-scoped Matomo reports with server-built segments (`pageUrl==`, `eventUrl==`) and reuses URL mapping and the page report of `TransitionsService`. Four read endpoints in `MatomoDashboardService`/`ApiController`. Three Lit components in a new `page-matomo.js`, sharing the loading base class moved out of `page-detail.js`.

**Tech Stack:** PHP 8.2, TYPO3 14.3, PHPUnit 11 (attributes), Lit (backend ES modules), Matomo 5 Reporting API.

**Spec:** `docs/superpowers/specs/2026-10-30-page-detail-devices-events-goals-design.md`

## Global Constraints

- Existing modal order unchanged: head, transitions, pageviews chart, **new tile row**, search queries.
- Events are scoped with segment `eventUrl==<url>`, never `pageUrl==` (counts events of the whole visit).
- Devices use segment `pageUrl==<url>`; device rows are mapped by the row's `segment` value (`deviceType==smartphone`), never by the translated label. Mobile = `smartphone`, `phablet`, `feature phone`; `desktop`; `tablet`; everything else `other`.
- Page segment and user segment are joined with `;` (user segment last). The user segment must be one defined in Matomo (existing check in `MatomoDashboardService::pageQuery`); users never send free-form segments.
- "On the way" = `max(0, nb_conversions_page_uniq − nb_conversions_entry)`.
- Series granularity: `week` when the range has more than `TransitionsService::WEEKLY_FROM` (92) days, else `day`.
- Segments not processed by Matomo: page has pageviews in the unsegmented page report but the page-segmented devices report has 0 visits → devices and events respond `available: false`.
- The events tree's names (level 3) load only when a category is expanded.
- All UI text in `locallang_js.xlf` (English) and `de.locallang_js.xlf` (German); no mention of caching.
- Unit tests run with:
  `docker run --rm --entrypoint "" -u 1000 -v /home/derspe/www/smart-home-insights-v14:/project:ro -v $PWD:/ext:ro -v <dir with bootstrap.php>:/s:ro -w /tmp ddev/ddev-webserver:v1.24.10-shi-v14-built php /project/vendor/bin/phpunit --bootstrap /s/bootstrap.php --no-configuration --cache-directory /tmp/pc /ext/Tests/Unit`
  where `bootstrap.php` requires `/project/vendor/autoload.php` and prepends PSR-4 `Derspe\SiteInsights\` → `/ext/Classes` and `Derspe\SiteInsights\Tests\` → `/ext/Tests`. Below: "run the unit tests" = this command, optionally with `--filter <Name>`.
- Commit messages in English, no tool attribution lines.

## Review Focus

1. URL with query string or umlauts (`/news/größe?x=1`) – segment value must be URL-encoded (`rawurlencode`) so `;`/`,`/`=` in the URL cannot break the segment. Test in Task 2.
2. User segment containing OR (`browserCode==FF,browserCode==CH`) – joined after `;` it must stay one segment (Matomo binds `,` tighter than `;`). Test in Task 2.
3. Page with pageviews but Matomo returns empty segmented reports → `available: false`, not zeros. Test in Task 2.
4. Page without any pageviews in the period → tiles show "no data", not "not available". Test in Task 2.
5. Category name requested by the client that Matomo did not return (tampered request) → 400. Test in Task 3.

---

### Task 1: Goals in the page report, shared helpers in TransitionsService

**Files:**
- Modify: `Classes/Service/Matomo/MatomoNormalizer.php` (`pageEngagement`)
- Modify: `Classes/Service/Matomo/TransitionsService.php` (`matomoUrl` public, new `pageReport`)
- Test: `Tests/Unit/Service/Matomo/MatomoNormalizerTest.php`, `Tests/Unit/Service/Matomo/TransitionsServiceTest.php`

**Interfaces:**
- Produces: `MatomoNormalizer::pageEngagement()` result gains `goals: list<array{id: int, entry: int, onTheWay: int}>` (summed over the rows, sorted by id; empty list when no `goals` column).
- Produces: `public function TransitionsService::matomoUrl(string $site, string $url): string` (visibility change only).
- Produces: `public function TransitionsService::pageReport(string $site, string $url, array $range, string $segment): array` – the normalized `pageEngagement` of an already mapped URL, cached under `[$site, 'matomo', 'engagement', 2, $url, $range, $segment]` for `LIFETIME`; `forUrl` uses it for both ranges (replace the private `engagement` cache keys with this method, cache version 2).

- [ ] **Step 1: Write the failing tests**

In `MatomoNormalizerTest`: `pageEngagementSumsGoalsPerGoalAcrossRows` – two rows, row 1 `goals: {"idgoal=5": {nb_conversions_entry: 3, nb_conversions_page_uniq: 3}, "idgoal=4": {nb_conversions_entry: 1, nb_conversions_page_uniq: 4}}`, row 2 `goals: {"idgoal=4": {nb_conversions_entry: 1, nb_conversions_page_uniq: 1}}` → `goals === [['id' => 4, 'entry' => 2, 'onTheWay' => 3], ['id' => 5, 'entry' => 3, 'onTheWay' => 0]]`. Extend the existing exact-array assertions (lines 78–83) with `'goals' => []`.

In `TransitionsServiceTest`: `thePageReportIsSharedByTransitionsAndCached` – `forUrl` then `pageReport` for the same mapped URL/range/segment issue only one `Actions.getPageUrl` request (count requests in `FakeHttp`). Update `$result['compare']` expectation with `'goals' => []`.

- [ ] **Step 2: Run the unit tests with `--filter 'pageEngagement|PageReport|FiguresOfThePageReport'`** – Expected: FAIL (missing `goals` key / method).
- [ ] **Step 3: Implement** – parse `goals` keys `idgoal=<n>` in `pageEngagement`; add `pageReport`, make `matomoUrl` public, route `forUrl` through `pageReport`.
- [ ] **Step 4: Run all unit tests** – Expected: all pass.
- [ ] **Step 5: Commit** – `git commit -m "Keep goal conversions of the page report and share it"`

### Task 2: PageMatomoService – devices and events

**Files:**
- Create: `Classes/Service/Matomo/PageMatomoService.php`
- Modify: `Classes/Service/Matomo/MatomoNormalizer.php` (new static helpers)
- Test: `Tests/Unit/Service/Matomo/PageMatomoServiceTest.php`, `Tests/Unit/Service/Matomo/MatomoNormalizerTest.php`

**Interfaces:**
- Consumes: `TransitionsService::matomoUrl`, `TransitionsService::pageReport`, `TransitionsService::WEEKLY_FROM`, `MatomoClient::bulk`, `ApiCache::remember`.
- Produces (`final readonly class PageMatomoService`, constructor `(MatomoClient $client, ApiCache $cache, TransitionsService $transitions)`, cache lifetime 3600 s, cache keys start `[$site, 'matomo', 'page<Name>', 1, …]`):
  - `devices(string $site, string $url, array $range, string $segment, ?array $compareRange): array` →
    `{available: bool, visits: int, page: {mobile: float, desktop: float, tablet: float, other: float}, site: {…same}, compare?: {…same}, compareRange?: range}` (shares 0..1; all 0.0 when no visits).
  - `events(string $site, string $url, array $range, string $segment, ?array $compareRange): array` →
    `{available: bool, total: int, pageviews: int, per100: float|null, categories: list<{label: string, count: int, actions: list<{label: string, count: int}>}>, period: 'day'|'week', series: list<{date: string, value: int, compare?: int}>, range, compare?: {total: int, pageviews: int, per100: float|null}, compareRange?}`.
  - `public static function pageSegment(string $dimension, string $url, string $segment): string` → `"$dimension==" . rawurlencode($url) . ($segment !== '' ? ';' . $segment : '')`.
- Produces in `MatomoNormalizer`: `deviceShares(mixed $data): array{visits: int, shares: array{mobile: float, desktop: float, tablet: float, other: float}}`, `eventCategories(mixed $data): list<…>` (as above, sorted by count desc), `eventSeries(mixed $data): list<{date, value}>` (sum of `nb_events` per period key, like `pageSeries`).

Matomo calls (one bulk per range): `DevicesDetection.getType` with `pageSegment('pageUrl', …)`, `DevicesDetection.getType` with only the user segment; `Events.getCategory` (`secondaryDimension=eventAction`, `expanded=1`) and `Events.getCategory` with `period=day|week`, `date=start,end` – both with `pageSegment('eventUrl', …)`. `events()` gets `available` and pageviews from `devices()` (cached) and `pageReport`.

- [ ] **Step 1: Write the failing tests** in `PageMatomoServiceTest` (setup like `TransitionsServiceTest`):
  - `devicesMapsRowsBySegmentValue` – rows `Smartphone/deviceType==smartphone 6`, `Phablet/deviceType==phablet 2`, `Desktop/deviceType==desktop 10`, `Tablet/deviceType==tablet 2`, `Fernseher/deviceType==tv 0` → `page === ['mobile' => 0.4, 'desktop' => 0.5, 'tablet' => 0.1, 'other' => 0.0]`, `visits === 20`.
  - `segmentsEncodeTheUrlAndKeepTheUserSegmentLast` – URL `https://alpha.example/news/größe?x=1;y`, segment `browserCode==FF,browserCode==CH` → sent segment `pageUrl==https%3A%2F%2Falpha.example%2Fnews%2Fgr%C3%B6%C3%9Fe%3Fx%3D1%3By;browserCode==FF,browserCode==CH` (Review Focus 1, 2).
  - `segmentedReportsWithoutVisitsAreNotAvailable` – page report 12 pageviews, segmented devices 0 visits → `devices()['available'] === false` and `events()['available'] === false` (Review Focus 3).
  - `aPageWithoutPageviewsIsAvailableWithoutData` – page report 0 pageviews, devices 0 → `available === true`, `visits === 0`, `events()['total'] === 0` (Review Focus 4).
  - `eventsUseTheEventUrlSegmentAndCountPer100Pageviews` – 7 events, 140 pageviews → `per100 === 5.0`; both Events calls carry `eventUrl==` segment, no call carries `pageUrl==` for events.
  - `eventSeriesIsWeeklyAbove92DaysAndAlignsTheComparison` – 120-day range → `period === 'week'`; compare values aligned by position like `pageSeries`.
- [ ] **Step 2: Run the unit tests with `--filter PageMatomoService`** – Expected: FAIL (class missing).
- [ ] **Step 3: Implement** `PageMatomoService` and the normalizer helpers per the interfaces above. Add a code comment at the `eventUrl` segment: Matomo segments select visits; `pageUrl` would count events of other pages, `eventUrl` was verified against the raw log on 2026-10-30.
- [ ] **Step 4: Run all unit tests** – Expected: all pass.
- [ ] **Step 5: Commit** – `git commit -m "Report devices and events of a page from Matomo"`

### Task 3: PageMatomoService – event names and goals

**Files:**
- Modify: `Classes/Service/Matomo/PageMatomoService.php`
- Test: `Tests/Unit/Service/Matomo/PageMatomoServiceTest.php`

**Interfaces:**
- Consumes: Task 1 `pageReport` (with `goals`), Task 2 `events`, `pageSegment`; `MatomoDashboardService::goals()` is not reused (it is uncached) – call `Goals.getGoals` via `ApiCache` here.
- Produces:
  - `eventNames(string $site, string $url, array $range, string $segment, string $category): array` → `{category: string, actions: list<{label: string, count: int, names: list<{label: string, count: int}>}>}`; `Events.getAction` with `secondaryDimension=eventName`, `expanded=1`, segment `pageSegment('eventUrl', …) ` + `;eventCategory==` + `rawurlencode($category)` inserted before the user segment. Throws `ApiException(…, 400)` when `$category` is not a label in `events()['categories']` of the same range (Review Focus 5).
  - `goals(string $site, string $url, array $range, string $segment, ?array $compareRange): array` → `{defined: bool, goals: list<{id: int, name: string, entry: int, onTheWay: int, compare?: {entry: int, onTheWay: int}}>, totals: {entry: int, onTheWay: int}, compareTotals?: {…}, compareRange?}`; every goal defined in Matomo appears (0 when the page report has none), order of `Goals.getGoals`; `defined === false` when Matomo has no goals.

- [ ] **Step 1: Write the failing tests**:
  - `eventNamesRejectACategoryMatomoDidNotReturn` – categories `Amazon`; request `Tool` → `ApiException` with status 400.
  - `eventNamesFilterByCategoryInsideThePageSegment` – sent segment `eventUrl==<enc>;eventCategory==Amazon` (no user segment) and the tree `Direkt-Klick → [B0DJ3284BS 3, B0G4NBBZLG 3]`.
  - `goalsListEveryDefinedGoalWithEntryAndOnTheWay` – goals 4 "Amazon Link CE", 5 "Amazon RTE-Link DB", page report goals only for 5 (`entry 3`, `page_uniq 3`) → `[{id 4, entry 0, onTheWay 0}, {id 5, entry 3, onTheWay 0}]`, totals `{entry 3, onTheWay 0}`.
  - `goalsWithoutDefinedGoalsAreFlagged` – `Goals.getGoals` returns `[]` → `defined === false`, `goals === []`.
- [ ] **Step 2: Run the unit tests with `--filter PageMatomoService`** – Expected: the four new tests FAIL.
- [ ] **Step 3: Implement** `eventNames` and `goals`.
- [ ] **Step 4: Run all unit tests** – Expected: all pass.
- [ ] **Step 5: Commit** – `git commit -m "Report event names and goal contributions of a page"`

### Task 4: Endpoints

**Files:**
- Modify: `Classes/Service/Matomo/MatomoDashboardService.php` (constructor gets `PageMatomoService`; four methods)
- Modify: `Classes/Controller/Backend/ApiController.php` (`dispatch`)
- Modify: `Classes/Security/AccessPolicy.php` (`READ_ENDPOINTS`)
- Test: `Tests/Unit/Security/AccessPolicyTest.php`, existing tests that construct `MatomoDashboardService` (adapt constructor calls)

**Interfaces:**
- Consumes: Task 2/3 methods; `MatomoDashboardService::pageQuery` for url/range/segment/compare validation and `TransitionsService::matomoUrl` mapping inside `PageMatomoService`.
- Produces endpoints (all `AccessPolicy::USER`, GET, same query parameters as `matomo/page-series`: `url`, `start`, `end`, `segment`, `compare`):
  - `matomo/page-devices` → `pageDevices(string $site, array $q): array`
  - `matomo/page-events` → `pageEvents(string $site, array $q): array`
  - `matomo/page-event-names` → `pageEventNames(string $site, array $q): array` (extra `category`, required, non-empty string ≤ 255 chars, else 400; no compare)
  - `matomo/page-goals` → `pageGoals(string $site, array $q): array`

- [ ] **Step 1: Write the failing tests** – four `yield` rows in `AccessPolicyTest` like line 28 (`'matomo page devices' => ['matomo/page-devices', false, [], AccessPolicy::USER]`, …).
- [ ] **Step 2: Run the unit tests with `--filter AccessPolicy`** – Expected: FAIL.
- [ ] **Step 3: Implement** the policy entries, the four service methods (each `pageQuery` then delegate) and the `dispatch` arms. Register nothing in `Services.yaml` unless autowiring fails (check `Configuration/Services.yaml`).
- [ ] **Step 4: Run all unit tests** – Expected: all pass.
- [ ] **Step 5: Commit** – `git commit -m "Add page endpoints for devices, events and goals"`

### Task 5: Tiles in the page details modal

**Files:**
- Create: `Resources/Public/JavaScript/page-loading.js` (move `SiLoading` and `softError` out of `page-detail.js`, export both)
- Create: `Resources/Public/JavaScript/page-matomo.js` (`<si-page-devices>`, `<si-page-events>`, `<si-page-goals>`)
- Modify: `Resources/Public/JavaScript/page-detail.js` (import from `page-loading.js` and `page-matomo.js`; new section)
- Modify: `Resources/Private/Language/locallang_js.xlf`, `Resources/Private/Language/de.locallang_js.xlf`
- Modify: `README.md` (page details section: one bullet for the three tiles)

**Interfaces:**
- Consumes: Task 4 endpoints; `SiLoading` (properties `site`, `endpoint`, `params`, state `data`/`error`/`reloading`); core helpers `delta`, `fmt`, `skeleton`, `t`, `icon`; `<si-chart>` like `SiPageViews`.
- Produces: in `SiPageDetail.render()` a third Matomo `<section>` after the pageviews section, heading `pageDetail.engagement`, containing `<div class="tiles">` with the three components, each with `.params=${{ url, start, end, segment, compare }}` like `si-page-views`. Tiles: CSS grid `repeat(3, minmax(0, 1fr))`, one column below 760px (same breakpoint as `.head`). Expanded views render full width below the tile row (the tile dispatches `si-expand` with its detail template, or the section owns an `expanded` state – implementer's choice).

Content per tile (spec "Placement and look"):
- Devices: stacked bar page (height 14px), thin bar site (6px), list with shares and change in percentage points (`+5 Pp`, green/red via `deltaText`) when `compare` exists. Colours `--si-indigo-6` mobile, `--si-teal-6` desktop, `--si-orange-6` tablet, `--si-gray-5` other.
- Events: `total`, `per100` (one decimal) with `delta`, `<si-chart type="area" height="60">` of `series` (+ compare line like `SiPageViews`), toggle "Show details" → tree: category rows (from `categories`, with actions), each category expandable; expanding a category fetches `matomo/page-event-names` with `category` once and shows names under each action.
- Goals: `totals.entry` and `totals.onTheWay` with `delta`; toggle → table (`si-table` or plain table) goal name | as entry | on the way, each with change.
- States: `skeleton` while loading; `available === false` → `pageDetail.segmentsUnavailable`; no events → `pageDetail.noEvents`; `defined === false` → `pageDetail.noGoals`; errors via `softError`.

New labels (en / de):
`pageDetail.engagement` Devices, events and goals / Geräte, Ereignisse und Ziele ·
`pageDetail.devices` Devices / Geräte · `pageDetail.devices.site` Whole site / Ganze Website ·
`pageDetail.devices.mobile` Mobile / Mobil · `pageDetail.devices.desktop` Desktop / Desktop · `pageDetail.devices.tablet` Tablet / Tablet · `pageDetail.devices.other` Other / Sonstige ·
`pageDetail.events` Events / Ereignisse · `pageDetail.events.per100` per 100 pageviews / je 100 Aufrufe ·
`pageDetail.goals` Goals / Ziele · `pageDetail.goals.entry` As entry page / Als Einstieg · `pageDetail.goals.onTheWay` On the way / Unterwegs · `pageDetail.goals.hint` Conversions of visits that started on this page, or that viewed it before converting / Abschlüsse von Besuchen, die hier begannen oder die Seite vor dem Ziel aufriefen ·
`pageDetail.showDetails` Show details / Details anzeigen · `pageDetail.hideDetails` Hide details / Details ausblenden ·
`pageDetail.noEvents` No events on this page in the period. / Keine Ereignisse auf dieser Seite im Zeitraum. ·
`pageDetail.noGoals` No goals are defined in Matomo. / In Matomo sind keine Ziele angelegt. ·
`pageDetail.segmentsUnavailable` Not available: this Matomo does not process segments on request. / Nicht verfügbar: Dieses Matomo berechnet Segmente nicht auf Anfrage.

- [ ] **Step 1: Move `SiLoading`/`softError` to `page-loading.js`**, import in `page-detail.js`; reload the page details in the backend – Expected: transitions, pageviews and queries render as before (no console errors).
- [ ] **Step 2: Add labels and `page-matomo.js` with the three components; wire the section into `SiPageDetail`.**
- [ ] **Step 3: Verify in the backend** (smart-home-insights, page `/news/roborock-integration-in-home-assistant`, 28 days, with and without compare): order head → transitions → pageviews → tiles → queries; devices bars sum to 100 %; events total equals Matomo's Events report for `eventUrl==` that page; expanding "Amazon" loads names; goals show entry 3 / on the way 0 for "Amazon RTE-Link DB" (Oct data); narrow window (<760px) stacks tiles. Screenshot via headless Chrome (memory: `chrome-headless-shell` with `env -u DISPLAY`).
- [ ] **Step 4: Update README** page details section; run all unit tests – Expected: all pass.
- [ ] **Step 5: Commit** – `git commit -m "Show devices, events and goals of a page in the page details"`
