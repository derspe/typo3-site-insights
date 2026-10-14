/**
 * Google Search Console views: performance, drilldown, tracker, indexing, settings.
 */
import { css, html, nothing } from 'lit';
import {
  SiElement, alertBox, badge, checkbox, errorState, fmt, icon, segmented, selectInput, skeleton,
  spinnerButton, statCard, statusColor, t, deltaText, getMeta,
} from '@derspe/site-insights/core.js';
import { JobRunner, jobProgressText } from '@derspe/site-insights/jobs.js';
import '@derspe/site-insights/table.js';
import '@derspe/site-insights/chart.js';

export const GSC_DEFAULT_RANGE = { period: 'range', date: 'last28', searchType: 'web', compare: '' };

const PRESETS = [
  { key: 'latest', period: 'day', date: 'today' },
  { key: 'previousDay', period: 'day', date: 'yesterday' },
  { key: '7d', period: 'range', date: 'last7' },
  { key: '28d', period: 'range', date: 'last28' },
  { key: '90d', period: 'range', date: 'last90' },
  { key: '1y', period: 'range', date: 'last365' },
];

function openSettings(element) {
  element.dispatchEvent(new CustomEvent('si-open-settings', { bubbles: true, composed: true, detail: { area: 'gsc' } }));
}

function compareLabel(compare) {
  return compare === 'year' ? t('compare.previousYear') : t('compare.previousPeriod');
}

/**
 * Time range, search type and compare controls.
 */
export function gscPicker(value, onChange, dataDate = '', { searchType = true } = {}) {
  const searchTypes = ['web', 'discover', 'news', 'image', 'video'].map((v) => ({ value: v, label: t(`gsc.searchType.${v}`) }));
  return html`
    <div class="group mb">
      ${segmented(PRESETS.map((p) => ({
        label: t(`preset.${p.key}`), value: p, active: value.period === p.period && value.date === p.date,
      })), (item) => onChange({ ...value, period: item.value.period, date: item.value.date }))}
      ${searchType ? selectInput(searchTypes, value.searchType, (v) => onChange({ ...value, searchType: v }), { minWidth: 120, label: t('gsc.searchType') }) : nothing}
      ${checkbox(t('compare.enable'), !!value.compare, (checked) => onChange({ ...value, compare: checked ? 'previous' : '' }))}
      ${value.compare ? selectInput([
        { value: 'previous', label: t('compare.previousPeriod') },
        { value: 'year', label: t('compare.previousYear') },
      ], value.compare, (v) => onChange({ ...value, compare: v }), { minWidth: 160 }) : nothing}
      ${dataDate ? html`<span class="xs dimmed ml-auto" title=${t('gsc.dataDate.hint')}>${t('gsc.dataDate', { date: fmt.date(dataDate) })}</span>` : nothing}
    </div>`;
}

function rangeParams(range, extra = {}) {
  return { period: range.period, date: range.date, searchType: range.searchType, compare: range.compare, ...extra };
}

function metricColumns(keyColumn, keyLabel, { compare = false, compareText = '', matomo = false, maxWidth = 280 } = {}) {
  const isPage = keyColumn === 'page';
  if (compare) {
    maxWidth = Math.min(maxWidth, matomo ? 150 : 180);
  }
  const columns = [
    { key: keyColumn, label: keyLabel, maxWidth, ...(isPage ? { render: (r) => html`<span title=${r.page}>${fmt.path(r.page)}</span>` } : {}) },
    { key: 'clicks', label: t('metric.clicks'), align: 'right', render: (r) => fmt.int(r.clicks) },
    { key: 'impressions', label: t('metric.impressionsShort'), align: 'right', render: (r) => fmt.int(r.impressions) },
    { key: 'ctr', label: t('metric.ctr'), align: 'right', render: (r) => fmt.pct(r.ctr) },
    { key: 'position', label: t('metric.positionShort'), align: 'right', render: (r) => fmt.dec(r.position) },
  ];
  if (compare) {
    columns.push({ key: 'clicksCompare', label: t('compare.short'), title: compareText, align: 'right', render: (r) => html`<span class="dimmed">${fmt.int(r.clicksCompare)}</span>` });
    columns.push({ key: 'clicksDelta', label: 'Δ', align: 'right', sortValue: (r) => (r.clicksDelta ? parseFloat(r.clicksDelta) : null), render: (r) => deltaText(r.clicksDelta, r.clicksDeltaPos) });
  }
  if (matomo) {
    columns.push({ key: 'matomoVisits', label: t('gsc.matomoVisitsShort'), title: t('gsc.matomoVisits'), align: 'right', render: (r) => html`<span class="dimmed">${fmt.int(r.matomoVisits)}</span>` });
  }
  return columns;
}

// ---------------------------------------------------------------------------
// Container with tabs
// ---------------------------------------------------------------------------

export class SiGsc extends SiElement {
  static properties = {
    tab: { type: String },
    range: { state: true },
    data: { state: true },
    error: { state: true },
    trackerKind: { state: true },
    selectedQuery: { state: true },
    selectedPage: { state: true },
  };

  constructor() {
    super();
    this.tab = 'performance';
    this.range = { ...GSC_DEFAULT_RANGE };
    this.data = null;
    this.error = null;
    this.trackerKind = 'query';
    this.selectedQuery = '';
    this.selectedPage = '';
    this.requestId = 0;
  }

  willUpdate(changed) {
    if ((changed.has('site') || changed.has('range')) && this.site) {
      this.load();
    }
  }

  async load() {
    const id = ++this.requestId;
    this.data = null;
    this.error = null;
    try {
      const data = await this.api('gsc/dashboard', { params: rangeParams(this.range) });
      if (id === this.requestId) {
        this.data = data;
      }
    } catch (error) {
      if (id === this.requestId) {
        this.error = error;
      }
    }
  }

  setTab(tab) {
    this.tab = tab;
    this.dispatchEvent(new CustomEvent('si-tab', { detail: { tab }, bubbles: true, composed: true }));
  }

  render() {
    const tabs = [
      ['performance', 'chartBar', t('gsc.tab.performance')],
      ['drilldown', 'search', t('gsc.tab.drilldown')],
      ['tracker', 'bookmarks', t('gsc.tab.tracker')],
      ['index', 'clipboardCheck', t('gsc.tab.index')],
      ['settings', 'settings', t('tab.settings')],
    ];
    return html`
      <div class="tabs" role="tablist">
        ${tabs.map(([key, iconName, label]) => html`<button type="button" role="tab" class="tab ${this.tab === key ? 'active' : ''}"
          aria-selected=${this.tab === key ? 'true' : 'false'} @click=${() => this.setTab(key)}>${icon(iconName, 14)}${label}</button>`)}
      </div>
      ${this.tab === 'performance' ? this.renderPerformance() : nothing}
      ${this.tab === 'drilldown' ? html`${gscPicker(this.range, (r) => { this.range = r; })}
        <si-gsc-drilldown .site=${this.site} .range=${this.range}></si-gsc-drilldown>` : nothing}
      ${this.tab === 'tracker' ? this.renderTracker() : nothing}
      ${this.tab === 'index' ? html`<si-gsc-index .site=${this.site}></si-gsc-index>` : nothing}
      ${this.tab === 'settings' ? html`<si-gsc-settings .site=${this.site}></si-gsc-settings>` : nothing}
    `;
  }

  renderPerformance() {
    const d = this.data;
    const cmp = d?.compare ? compareLabel(d.compare) : '';
    return html`
      ${gscPicker(this.range, (r) => { this.range = r; }, d?.dataDate)}
      ${this.error ? html`<div class="mb">${errorState(this.error, () => this.setTab('settings'))}</div>` : nothing}
      <div class="grid cols-2 mb">
        ${this.renderKpis(d)}
        <si-gsc-appearance .site=${this.site} .range=${this.range}></si-gsc-appearance>
      </div>
      <div class="grid cols-2 mb">
        ${this.renderPerformanceChart(d, cmp)}
        <si-gsc-visibility .site=${this.site} .range=${this.range}></si-gsc-visibility>
      </div>
      <div class="grid cols-2 mb">
        <div class="paper">
          <h4 class="card-title">${t('gsc.topQueries')}</h4>
          ${!d ? skeleton(160) : d.topQueries.length === 0 ? html`<div class="empty">${t('empty.noData')}</div>` : html`
            <si-table .columns=${metricColumns('query', t('metric.query'), { compare: !!d.compare, compareText: cmp })} .rows=${d.topQueries} sort-key="clicks"></si-table>`}
        </div>
        <div class="paper">
          <h4 class="card-title">${t('gsc.topPages')}</h4>
          ${!d ? skeleton(160) : d.topPages.length === 0 ? html`<div class="empty">${t('empty.noData')}</div>` : html`
            <si-table .columns=${metricColumns('page', t('metric.page'), { compare: !!d.compare, compareText: cmp, matomo: d.topPages.some((r) => r.matomoVisits != null) })}
              .rows=${d.topPages} sort-key="clicks"></si-table>`}
        </div>
      </div>
      <div class="grid cols-2">
        <si-gsc-cannibalization .site=${this.site} .range=${this.range}></si-gsc-cannibalization>
        ${this.renderBrandSplit(d)}
      </div>
    `;
  }

  renderKpis(d) {
    const head = html`<div class="section-head">${icon('chartBar', 15, 'var(--si-blue-6)')}${t('gsc.kpis')}</div>`;
    if (!d) {
      return html`<div>${head}<div class="grid-tight">${[0, 1, 2, 3].map(() => html`<div class="stat compact">${skeleton(56)}</div>`)}</div></div>`;
    }
    const k = d.kpis;
    const c = d.kpisCompare ?? {};
    return html`<div>${head}<div class="grid-tight">
      ${statCard({ compact: true, label: t('metric.clicks'), value: fmt.int(k.clicks), raw: k.clicks, compare: c.clicks, color: 'green', icon: 'mouse' })}
      ${statCard({ compact: true, label: t('metric.impressions'), value: fmt.int(k.impressions), raw: k.impressions, compare: c.impressions, color: 'blue', icon: 'eye' })}
      ${statCard({ compact: true, label: t('metric.ctr'), value: fmt.pct(k.ctr), raw: k.ctr, compare: c.ctr, color: 'orange', icon: 'percentage' })}
      ${statCard({ compact: true, label: t('metric.avgPosition'), value: fmt.dec(k.position), raw: k.position, compare: c.position, invert: true, color: 'teal', icon: 'number' })}
    </div></div>`;
  }

  renderPerformanceChart(d, cmp) {
    const series = [
      { key: 'clicks', label: t('metric.clicks'), color: 'green' },
      { key: 'impressions', label: t('metric.impressions'), color: 'blue', axis: 'right' },
    ];
    if (d?.compare) {
      series.push({ key: 'clicksCompare', label: `${t('metric.clicks')} ${cmp}`, color: 'green', shade: 3 });
      series.push({ key: 'impressionsCompare', label: `${t('metric.impressions')} ${cmp}`, color: 'blue', shade: 3, axis: 'right' });
    }
    return html`<div class="paper">
      <h4 class="card-title">${t('gsc.chart.title')}</h4>
      ${!d ? skeleton(220) : d.series.length === 0 ? html`<div class="empty">${t('empty.noData')}</div>` : html`
        <si-chart type="line" .data=${d.series} .series=${series} height="220" legend ?dots=${d.series.length <= 30}
          y-label=${t('metric.clicks')} right-label=${t('metric.impressions')}></si-chart>`}
    </div>`;
  }

  renderBrandSplit(d) {
    const split = d?.brandSplit ?? { brand: 0, nonBrand: 0 };
    const total = split.brand + split.nonBrand;
    const share = (v) => (total > 0 ? fmt.dec((v / total) * 100, 1) : fmt.dec(0, 1));
    return html`<div class="paper">
      <h4 class="card-title">${t('gsc.brand.title')}</h4>
      ${d && !d.brandConfigured ? html`<div class="mb-sm">${alertBox(t('gsc.brand.notConfigured'), { color: 'yellow' })}</div>` : nothing}
      <div class="grid-tight mb">
        <div class="stat"><div class="label">${t('gsc.brand.brand')}</div><div class="value" style="color:var(--si-violet-6)">${fmt.int(split.brand)}</div>
          <div class="xs dimmed">${t('gsc.brand.share', { pct: share(split.brand) })}</div></div>
        <div class="stat"><div class="label">${t('gsc.brand.nonBrand')}</div><div class="value" style="color:var(--si-cyan-6)">${fmt.int(split.nonBrand)}</div>
          <div class="xs dimmed">${t('gsc.brand.share', { pct: share(split.nonBrand) })}</div></div>
      </div>
      ${!d ? skeleton(140) : html`<si-chart type="bar" height="140" x-format="text"
        .data=${[{ name: t('gsc.brand.brand'), clicks: split.brand }, { name: t('gsc.brand.nonBrand'), clicks: split.nonBrand }]}
        x-key="name" .series=${[{ key: 'clicks', label: t('metric.clicks'), color: 'blue' }]}></si-chart>`}
    </div>`;
  }

  renderTracker() {
    return html`<div class="grid cols-2">
      <si-gsc-tracker-list .site=${this.site} .kind=${this.trackerKind}
        .selectedQuery=${this.selectedQuery} .selectedPage=${this.selectedPage}
        @si-select=${(e) => {
          this.trackerKind = e.detail.kind;
          if (e.detail.kind === 'query') {
            this.selectedQuery = e.detail.key;
          } else {
            this.selectedPage = e.detail.key;
          }
        }}
        @si-kind=${(e) => { this.trackerKind = e.detail.kind; }}></si-gsc-tracker-list>
      <si-gsc-tracker-detail .site=${this.site} .kind=${this.trackerKind}
        .selection=${this.trackerKind === 'query' ? this.selectedQuery : this.selectedPage}></si-gsc-tracker-detail>
    </div>`;
  }
}

// ---------------------------------------------------------------------------
// Search appearance (rich result types)
// ---------------------------------------------------------------------------

class SiGscAppearance extends SiElement {
  static properties = { range: { attribute: false }, items: { state: true } };

  constructor() {
    super();
    this.range = GSC_DEFAULT_RANGE;
    this.items = null;
  }

  willUpdate(changed) {
    if ((changed.has('range') || changed.has('site')) && this.site) {
      this.items = null;
      this.api('gsc/appearance', { params: rangeParams(this.range) })
        .then((d) => { this.items = d.items ?? []; })
        .catch(() => { this.items = []; });
    }
  }

  render() {
    const pretty = (type) => String(type).toLowerCase().replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
    return html`<div>
      <div class="section-head" title=${t('gsc.appearance.hint')}>${icon('sparkles', 15, 'var(--si-grape-6)')}${t('gsc.appearance.title')}</div>
      ${!this.items ? html`<div class="grid-tight">${[0, 1].map(() => html`<div class="stat compact">${skeleton(56)}</div>`)}</div>`
        : this.items.length === 0 ? html`<div class="xs dimmed">${t('gsc.appearance.empty')}</div>`
        : html`<div class="grid-tight">${this.items.map((it) => html`
          <div class="stat compact">
            <div class="label truncate" title=${pretty(it.type)}>${pretty(it.type)}</div>
            <div class="group-xs nowrap" style="align-items:baseline">
              <span class="value" style="color:var(--si-grape-6)">${fmt.int(it.clicks)}</span>
              <span class="xs dimmed">${fmt.pct(it.ctr, 1)}</span>
            </div>
            <div class="xs dimmed">${t('gsc.appearance.line', { impressions: fmt.int(it.impressions), position: fmt.dec(it.position) })}</div>
          </div>`)}</div>`}
    </div>`;
  }
}

// ---------------------------------------------------------------------------
// Visibility index
// ---------------------------------------------------------------------------

class SiGscVisibility extends SiElement {
  static properties = { range: { attribute: false }, series: { state: true }, compact: { type: Boolean } };

  constructor() {
    super();
    this.range = { period: 'range', date: 'last90' };
    this.series = null;
    this.compact = false;
  }

  willUpdate(changed) {
    if ((changed.has('range') || changed.has('site')) && this.site) {
      this.series = null;
      this.api('gsc/visibility', { params: { period: this.range.period, date: this.range.date } })
        .then((d) => { this.series = d.series ?? []; })
        .catch(() => { this.series = []; });
    }
  }

  render() {
    const s = this.series;
    const last = s?.length ? s[s.length - 1] : null;
    const first = s?.length ? s[0] : null;
    const change = last && first && first.score > 0 ? ((last.score - first.score) / first.score) * 100 : null;
    const height = this.compact ? 280 : 200;
    return html`<div class=${this.compact ? '' : 'paper'}>
      <div class="group-xs mb-sm" style="align-items:baseline">
        ${this.compact ? nothing : html`<span class="card-title" style="margin:0">${t('gsc.visibility.title')}</span>`}
        ${last ? html`<span class="fw7">${fmt.dec(last.score, 0)}</span>` : nothing}
        ${change !== null ? badge(t('gsc.visibility.since', { change: `${change >= 0 ? '+' : ''}${fmt.dec(change, 1)}`, date: fmt.date(first.date) }), change >= 0 ? 'green' : 'red', 'light') : nothing}
      </div>
      ${!s ? skeleton(height) : s.length === 0 ? html`<div class="empty">${t('gsc.visibility.empty')}</div>` : html`
        <si-chart type="area" curve="monotone" height=${height} .data=${s} .series=${[{ key: 'score', label: t('gsc.visibility.series'), color: 'indigo' }]}></si-chart>`}
      ${this.compact ? nothing : html`<div class="xs center" style="color:var(--si-faint);margin-top:6px" title=${t('gsc.visibility.hint')}>${t('gsc.visibility.formula')}</div>`}
    </div>`;
  }
}

// ---------------------------------------------------------------------------
// Cannibalization
// ---------------------------------------------------------------------------

class SiGscCannibalization extends SiElement {
  static properties = { range: { attribute: false }, items: { state: true }, error: { state: true }, min: { state: true } };

  static styles = [...SiElement.styles, css`
    table { width: 100%; border-collapse: collapse; font-size: 12px; }
    td { padding: 6px 8px; border-bottom: 1px solid var(--si-border); }
    tr:last-child td { border-bottom: 0; }
  `];

  constructor() {
    super();
    this.range = GSC_DEFAULT_RANGE;
    this.items = null;
    this.error = null;
    this.min = 50;
  }

  willUpdate(changed) {
    if ((changed.has('range') || changed.has('site')) && this.site) {
      this.items = null;
      this.error = null;
      this.api('gsc/cannibalization', { params: rangeParams(this.range, { compare: '' }) })
        .then((d) => { this.items = d.items; this.min = d.minImpressions; })
        .catch((e) => { this.error = e; });
    }
  }

  render() {
    return html`<div class="paper">
      <h4 class="card-title">${t('gsc.cannibalization.title')}</h4>
      <div class="xs dimmed mb">${t('gsc.cannibalization.hint', { min: this.min })}</div>
      ${this.error ? html`<div class="c-red sm">${this.error.message}</div>`
        : !this.items ? skeleton(200)
        : this.items.length === 0 ? html`<div class="empty">${t('gsc.cannibalization.empty')}</div>`
        : html`<div class="stack">${this.items.map((item) => html`
          <div class="paper sm">
            <div class="xs dimmed upper fw6">${t('gsc.cannibalization.query')}</div>
            <div class="fw7 mb-sm" style="font-size:16px" title=${item.query}>${item.query}</div>
            <table><tbody>${item.pages.map((p) => html`<tr>
              <td style="max-width:280px" class="truncate" title=${p.page}>${fmt.path(p.page)}</td>
              <td style="text-align:right;white-space:nowrap">${t('gsc.cannibalization.clicks', { n: fmt.int(p.clicks) })}</td>
              <td style="text-align:right;white-space:nowrap" class="dimmed">${t('gsc.cannibalization.impressions', { n: fmt.int(p.impressions) })}</td>
              <td style="text-align:right;white-space:nowrap" class="dimmed">${t('gsc.cannibalization.ctr', { n: fmt.pct(p.impressions > 0 ? p.clicks / p.impressions : 0, 1) })}</td>
              <td style="text-align:right;white-space:nowrap">${t('gsc.cannibalization.position', { n: fmt.dec(p.position) })}</td>
            </tr>`)}</tbody></table>
          </div>`)}</div>`}
    </div>`;
  }
}

// ---------------------------------------------------------------------------
// Drilldown (query x page)
// ---------------------------------------------------------------------------

const COUNTRIES = ['', 'deu', 'aut', 'che', 'usa', 'gbr', 'fra', 'ita', 'esp', 'nld'];

class SiGscDrilldown extends SiElement {
  static properties = {
    range: { attribute: false }, country: { state: true }, device: { state: true },
    search: { state: true }, rows: { state: true }, error: { state: true },
  };

  constructor() {
    super();
    this.range = GSC_DEFAULT_RANGE;
    this.country = '';
    this.device = '';
    this.search = '';
    this.rows = null;
    this.error = null;
  }

  willUpdate(changed) {
    if ((changed.has('range') || changed.has('site') || changed.has('country') || changed.has('device')) && this.site) {
      this.rows = null;
      this.error = null;
      this.api('gsc/drilldown', { params: rangeParams(this.range, { compare: '', country: this.country, device: this.device }) })
        .then((d) => { this.rows = d.rows; })
        .catch((e) => { this.error = e; });
    }
  }

  render() {
    const countryOptions = COUNTRIES.map((c) => ({ value: c, label: c ? t(`country.${c}`) : t('gsc.allCountries') }));
    const deviceOptions = ['', 'DESKTOP', 'MOBILE', 'TABLET'].map((d) => ({ value: d, label: d ? t(`device.${d.toLowerCase()}`) : t('gsc.allDevices') }));
    const needle = this.search.toLowerCase();
    const rows = this.rows && needle ? this.rows.filter((r) => r.query.toLowerCase().includes(needle) || r.page.toLowerCase().includes(needle)) : this.rows;
    const columns = [
      { key: 'query', label: t('metric.query'), maxWidth: 240 },
      { key: 'page', label: t('metric.page'), maxWidth: 280, render: (r) => html`<span title=${r.page}>${fmt.path(r.page)}</span>` },
      ...metricColumns('query', '').slice(1),
    ];
    return html`<div class="paper">
      <div class="group mb">
        ${selectInput(countryOptions, this.country, (v) => { this.country = v; }, { minWidth: 140 })}
        ${selectInput(deviceOptions, this.device, (v) => { this.device = v; }, { minWidth: 140 })}
        <input class="input xs" style="max-width:280px" type="search" placeholder=${t('gsc.drilldown.search')} .value=${this.search}
          @input=${(e) => { this.search = e.target.value; }}>
      </div>
      ${this.error ? errorState(this.error, () => openSettings(this))
        : !rows ? skeleton(300)
        : rows.length === 0 ? html`<div class="empty">${t('empty.noData')}</div>`
        : html`<si-table .columns=${columns} .rows=${rows} sort-key="clicks" page-size="50"></si-table>`}
    </div>`;
  }
}

// ---------------------------------------------------------------------------
// Tracker (from daily snapshots)
// ---------------------------------------------------------------------------

class SiGscTrackerList extends SiElement {
  static properties = {
    kind: { type: String }, selectedQuery: { type: String }, selectedPage: { type: String },
    data: { state: true }, order: { state: true },
  };

  constructor() {
    super();
    this.kind = 'query';
    this.order = 'clicks';
    this.data = null;
  }

  willUpdate(changed) {
    if ((changed.has('kind') || changed.has('order') || changed.has('site')) && this.site) {
      this.data = null;
      this.api('gsc/tracker', { params: { dimension: this.kind, order: this.order } })
        .then((d) => { this.data = d; })
        .catch(() => { this.data = { items: [] }; });
    }
  }

  render() {
    const keyName = this.kind;
    const label = this.kind === 'query' ? t('gsc.tracker.queries') : t('gsc.tracker.pages');
    const columns = [
      { key: '_rank', label: '#', sortable: false, render: (r, i) => html`<span class="dimmed">${i + 1}</span>` },
      { key: keyName, label: this.kind === 'query' ? t('metric.query') : t('metric.page'), sortable: false, maxWidth: 200,
        render: (r) => (this.kind === 'page' ? html`<span title=${r.page}>${fmt.path(r.page)}</span>` : r.query) },
      { key: 'clicks', label: t('metric.clicks'), align: 'right', render: (r) => fmt.int(r.clicks) },
      { key: 'impressions', label: t('metric.impressionsShort'), align: 'right', render: (r) => fmt.int(r.impressions) },
      { key: 'ctr', label: t('metric.ctr'), align: 'right', render: (r) => fmt.pct(r.ctr) },
      { key: 'position', label: t('metric.positionShort'), align: 'right', render: (r) => fmt.dec(r.position) },
    ];
    const selected = this.kind === 'query' ? this.selectedQuery : this.selectedPage;
    return html`
      <div class="tabs" style="margin-bottom:12px">
        ${['query', 'page'].map((k) => html`<button type="button" class="tab ${this.kind === k ? 'active' : ''}"
          @click=${() => this.dispatchEvent(new CustomEvent('si-kind', { detail: { kind: k } }))}>${k === 'query' ? t('gsc.tracker.queries') : t('gsc.tracker.pages')}</button>`)}
      </div>
      <div class="paper">
        <div class="group between mb-sm">
          <span class="card-title" style="margin:0">${this.data ? t('gsc.tracker.top', { n: this.data.items.length, label }) : label}</span>
          <span class="xs dimmed" title=${t('gsc.tracker.hint')}>${this.data?.from ? t('gsc.tracker.window', { from: fmt.date(this.data.from), to: fmt.date(this.data.to), days: this.data.days }) : '–'}</span>
        </div>
        ${!this.data ? skeleton(300) : this.data.items.length === 0 ? html`<div class="empty">${t('gsc.tracker.empty')}</div>` : html`
          <si-table .columns=${columns} .rows=${this.data.items} sort-key=${this.order} sort-dir=${this.order === 'position' ? 'asc' : 'desc'}
            server-sort clickable row-key=${keyName} .selected=${selected} page-size="0"
            @sort=${(e) => { this.order = e.detail.key; }}
            @row-click=${(e) => this.dispatchEvent(new CustomEvent('si-select', { detail: { kind: this.kind, key: e.detail.key } }))}></si-table>`}
      </div>`;
  }
}

class SiGscTrackerDetail extends SiElement {
  static properties = { kind: { type: String }, selection: { type: String }, data: { state: true } };

  constructor() {
    super();
    this.kind = 'query';
    this.selection = '';
    this.data = null;
  }

  willUpdate(changed) {
    if ((changed.has('kind') || changed.has('selection') || changed.has('site')) && this.site) {
      this.data = null;
      if (this.selection) {
        this.api('gsc/tracker/series', { params: { dimension: this.kind, key: this.selection } })
          .then((d) => { this.data = d; })
          .catch(() => { this.data = { series: [] }; });
      }
    }
  }

  render() {
    if (!this.selection) {
      return html`<div class="paper" style="margin-top:58px"><div class="empty">${this.kind === 'query' ? t('gsc.tracker.selectQuery') : t('gsc.tracker.selectPage')}</div></div>`;
    }
    const s = this.data?.series ?? [];
    return html`<div class="paper" style="margin-top:58px">
      <div class="group between nowrap mb-sm">
        <span class="card-title truncate" style="margin:0" title=${this.selection}>${this.selection}</span>
        ${this.data?.currentRank ? badge(t('gsc.tracker.rank', { rank: this.data.currentRank }), 'blue') : nothing}
      </div>
      ${!this.data ? skeleton(260) : s.length === 0 ? html`<div class="empty">${t('gsc.tracker.noSeries')}</div>` : html`
        <div class="xs dimmed mb-sm">${t('gsc.chart.title')}</div>
        <si-chart type="line" height="140" legend ?dots=${s.length <= 30} .data=${s}
          .series=${[{ key: 'clicks', label: t('metric.clicks'), color: 'green' }, { key: 'impressions', label: t('metric.impressions'), color: 'blue', axis: 'right' }]}></si-chart>
        <div class="xs dimmed mb-sm mt">${t('metric.position')}</div>
        <si-chart type="area" height="120" reversed ?dots=${s.length <= 30} .data=${s} .series=${[{ key: 'position', label: t('metric.position'), color: 'orange' }]}></si-chart>
        <div class="xs dimmed mb-sm mt">${t('gsc.visibility.series')}</div>
        <si-chart type="area" curve="monotone" height="120" ?dots=${s.length <= 30} .data=${s} .series=${[{ key: 'visibility', label: t('gsc.visibility.series'), color: 'indigo' }]}></si-chart>`}
    </div>`;
  }
}

// ---------------------------------------------------------------------------
// Indexing (URL inspection)
// ---------------------------------------------------------------------------

class SiGscIndex extends SiElement {
  static properties = {
    url: { state: true }, single: { state: true }, singleLoading: { state: true }, singleError: { state: true },
    pages: { state: true }, pagesError: { state: true }, filter: { state: true }, job: { state: true },
  };

  constructor() {
    super();
    this.url = '';
    this.single = null;
    this.singleLoading = false;
    this.singleError = null;
    this.pages = null;
    this.pagesError = null;
    this.filter = 'all';
    this.job = null;
    this.runner = null;
  }

  willUpdate(changed) {
    if (changed.has('site') && this.site) {
      this.runner?.stop();
      this.runner = new JobRunner(this.site, (job) => { this.job = job; });
      this.loadPages();
      this.runner.resume('index_scan').then((job) => job && this.loadPages()).catch(() => {});
    }
  }

  disconnectedCallback() {
    super.disconnectedCallback();
    this.runner?.stop();
  }

  loadPages() {
    this.pagesError = null;
    this.api('gsc/index/pages')
      .then((d) => { this.pages = d; })
      .catch((e) => { this.pagesError = e; this.pages = { items: [], total: 0, inspected: 0 }; });
  }

  async check(force = false) {
    this.singleLoading = true;
    this.singleError = null;
    try {
      this.single = await this.api('gsc/inspect', { method: 'POST', body: { url: this.url.trim(), force } });
    } catch (e) {
      this.singleError = e;
    } finally {
      this.singleLoading = false;
    }
  }

  async startScan() {
    try {
      await this.runner.start('index_scan', { force: false });
    } catch (e) {
      this.pagesError = e;
    }
    this.loadPages();
  }

  render() {
    return html`<div class="stack">
      ${this.renderSingle()}
      ${this.renderAll()}
    </div>`;
  }

  renderSingle() {
    const d = this.single;
    const row = (label, value) => html`<div class="group-xs"><span class="xs dimmed">${label}</span><code>${value ?? '–'}</code></div>`;
    return html`
      <div class="paper">
        <form class="group end nowrap" @submit=${(e) => { e.preventDefault(); this.check(false); }}>
          <div class="field grow">
            <label for="si-url">${t('gsc.index.single')}</label>
            <input id="si-url" class="input" type="url" placeholder="https://…" .value=${this.url} @input=${(e) => { this.url = e.target.value; }}>
          </div>
          ${spinnerButton(t('gsc.index.check'), { loading: this.singleLoading, disabled: !this.url, iconName: 'search', onClick: () => this.check(false) })}
        </form>
      </div>
      ${this.singleError ? errorState(this.singleError, () => openSettings(this)) : nothing}
      ${this.singleLoading && !d ? skeleton(180) : nothing}
      ${d ? html`<div class="paper">
        <div class="group between mb">
          <div class="group-xs">${badge(d.indexStatus ?? '–', statusColor(d.indexStatus))}<span class="sm dimmed">${d.coverageState ?? '–'}</span></div>
          <div class="group-xs">
            <span class="xs dimmed">${t('gsc.index.lastCheck', { ago: fmt.timeAgo(d.inspectedAt) })} ${d.cacheHit ? t('gsc.index.fromCache') : ''}</span>
            ${spinnerButton(t('gsc.index.recheck'), { size: 'xs', variant: 'subtle', iconName: 'refresh', loading: this.singleLoading, onClick: () => this.check(true) })}
          </div>
        </div>
        <div class="stack-xs">
          <div class="group-xs"><span class="xs dimmed">${t('gsc.index.structuredData')}</span>
            ${badge(d.richVerdict ?? t('gsc.index.none'), statusColor(d.richVerdict))}
            ${(d.richTypes ?? []).map((type) => badge(type, 'blue', 'light'))}</div>
          ${row(t('gsc.index.crawledAs'), d.crawledAs)}
          ${row(t('gsc.index.pageFetch'), d.pageFetchState)}
          ${row(t('gsc.index.robots'), d.robotsTxtState)}
          ${row(t('gsc.index.lastCrawl'), d.lastCrawl ? new Date(d.lastCrawl).toLocaleString() : null)}
          ${row(t('gsc.index.referring'), d.referringUrlsCount ?? 0)}
        </div>
      </div>` : nothing}`;
  }

  renderAll() {
    const running = this.job && (this.job.status === 'running' || this.job.status === 'pending');
    const filters = [
      ['all', t('gsc.index.filter.all')],
      ['indexed', t('gsc.index.filter.indexed')],
      ['notindexed', t('gsc.index.filter.notIndexed')],
      ['uninspected', t('gsc.index.filter.uninspected')],
      ['sdfail', t('gsc.index.filter.sdFail')],
    ].map(([value, label]) => ({ value, label }));
    const items = (this.pages?.items ?? []).filter((i) => {
      switch (this.filter) {
        case 'indexed': return i.indexStatus === 'PASS';
        case 'notindexed': return i.inspectedAt && i.indexStatus !== 'PASS';
        case 'uninspected': return !i.inspectedAt;
        case 'sdfail': return i.richVerdict === 'FAIL' || i.richVerdict === 'PARTIAL';
        default: return true;
      }
    });
    const columns = [
      { key: 'url', label: t('gsc.index.col.page'), maxWidth: 320, sortValue: (r) => fmt.path(r.url), render: (r) => fmt.path(r.url) },
      { key: 'indexStatus', label: t('gsc.index.col.index'), render: (r) => badge(r.indexStatus ?? '?', statusColor(r.indexStatus)) },
      { key: 'coverageState', label: t('gsc.index.col.coverage'), render: (r) => html`<span class="dimmed">${r.coverageState ?? '–'}</span>` },
      { key: 'richVerdict', label: t('gsc.index.structuredData'), render: (r) => html`<div class="group-xs" style="flex-wrap:wrap">
        ${r.inspectedAt ? badge(r.richVerdict ?? t('gsc.index.none'), statusColor(r.richVerdict)) : html`<span class="dimmed">–</span>`}
        ${(r.richTypes ?? []).map((type) => badge(type, 'blue', 'light'))}</div>` },
      { key: 'inspectedAt', label: t('gsc.index.col.checked'), align: 'right', render: (r) => html`<span class="dimmed">${fmt.timeAgo(r.inspectedAt)}</span>` },
    ];
    const progress = this.job?.total ? Math.round((this.job.done / this.job.total) * 100) : 0;
    return html`
      <div class="paper">
        <div class="group between">
          <div>
            <div class="card-title" style="margin:0">${t('gsc.index.all')}</div>
            <div class="xs dimmed">${this.pages ? t('gsc.index.counter', { inspected: this.pages.inspected, total: this.pages.total }) : t('state.loading')} · ${t('gsc.index.cacheHint')}</div>
          </div>
          <div class="group-xs">
            ${selectInput(filters, this.filter, (v) => { this.filter = v; }, { minWidth: 190 })}
            ${spinnerButton(running ? t('gsc.index.scanRunning') : t('gsc.index.scanStart'), { size: 'xs', iconName: 'scan', loading: running, onClick: () => this.startScan() })}
          </div>
        </div>
        ${this.job ? html`<div class="stack-xs mt">
          ${running ? html`<div class="progress"><div style="width:${progress}%"></div></div>` : nothing}
          <span class="xs ${this.job.status === 'error' ? 'c-red' : 'dimmed'} truncate">${jobProgressText(this.job)}</span>
        </div>` : nothing}
      </div>
      ${this.pagesError ? errorState(this.pagesError, () => openSettings(this)) : nothing}
      <div class="paper">
        ${!this.pages ? skeleton(300) : items.length === 0 ? html`<div class="empty">${t('gsc.index.empty')}</div>` : html`
          <si-table .columns=${columns} .rows=${items} page-size="100"></si-table>`}
      </div>`;
  }
}

// ---------------------------------------------------------------------------
// Settings
// ---------------------------------------------------------------------------

class SiGscSettings extends SiElement {
  static properties = {
    form: { state: true }, values: { state: true }, status: { state: true }, saving: { state: true },
    saved: { state: true }, error: { state: true }, job: { state: true }, backfillDays: { state: true }, notice: { state: true },
  };

  constructor() {
    super();
    this.form = null;
    this.values = null;
    this.status = null;
    this.saving = false;
    this.saved = false;
    this.error = null;
    this.job = null;
    this.backfillDays = 90;
    this.notice = null;
    this.runner = null;
    this.onMessage = (event) => {
      if (event.origin !== window.location.origin || event.data?.source !== 'derspe-site-insights') {
        return;
      }
      this.notice = event.data.status;
      this.loadStatus();
    };
  }

  connectedCallback() {
    super.connectedCallback();
    window.addEventListener('message', this.onMessage);
  }

  disconnectedCallback() {
    super.disconnectedCallback();
    window.removeEventListener('message', this.onMessage);
    this.runner?.stop();
  }

  willUpdate(changed) {
    if (changed.has('site') && this.site) {
      this.load();
      this.runner?.stop();
      this.runner = new JobRunner(this.site, (job) => { this.job = job; });
      this.runner.resume('backfill').catch(() => {});
    }
  }

  async load() {
    try {
      const v = await this.api('settings');
      this.values = v;
      this.form = {
        gsc_oauth_client_id: v.gsc_oauth_client_id ?? '',
        gsc_oauth_client_secret: '',
        gsc_site_url: v.gsc_site_url ?? '',
        gsc_brand_terms: (v.gsc_brand_terms ?? []).join(', '),
        gsc_snapshot_top_n: v.gsc_snapshot_top_n ?? 100,
        gsc_top_n: v.gsc_top_n ?? 50,
        gsc_sitemap_url: v.gsc_sitemap_url ?? '',
        gsc_extra_source_urls: (v.gsc_extra_source_urls ?? []).join(', '),
        gsc_extra_include: v.gsc_extra_include ?? '',
      };
    } catch (e) {
      this.error = e;
    }
    this.loadStatus();
  }

  loadStatus() {
    this.api('gsc/auth/status').then((s) => { this.status = s; }).catch(() => {});
  }

  async save() {
    this.saving = true;
    this.error = null;
    try {
      const values = { ...this.form };
      values.gsc_snapshot_top_n = Number(values.gsc_snapshot_top_n) || 100;
      values.gsc_top_n = Number(values.gsc_top_n) || 50;
      await this.api('settings', { method: 'POST', body: { values } });
      await this.load();
      this.saved = true;
      setTimeout(() => { this.saved = false; }, 2000);
    } catch (e) {
      this.error = e;
    } finally {
      this.saving = false;
    }
  }

  async connect() {
    this.notice = null;
    // Open the window synchronously (popup blockers), then navigate it to Google
    const popup = window.open('', 'derspe-site-insights-oauth', 'width=520,height=680');
    try {
      const { url } = await this.api('gsc/auth/start', { method: 'POST' });
      if (popup) {
        popup.location.href = url;
      } else {
        window.location.href = url;
      }
    } catch (e) {
      popup?.close();
      this.error = e;
    }
  }

  async disconnect() {
    await this.api('gsc/auth/disconnect', { method: 'POST' });
    this.loadStatus();
  }

  field(key, label, { description = '', placeholder = '', type = 'text' } = {}) {
    return html`<div class="field">
      <label for="f-${key}">${label}</label>
      ${description ? html`<span class="description">${description}</span>` : nothing}
      <input id="f-${key}" class="input" type=${type} .value=${String(this.form[key] ?? '')} placeholder=${placeholder}
        ?disabled=${!getMeta().isAdmin} autocomplete="off"
        @input=${(e) => { this.form = { ...this.form, [key]: e.target.value }; }}>
    </div>`;
  }

  render() {
    if (!this.form) {
      return this.error ? errorState(this.error) : skeleton(400);
    }
    const isAdmin = getMeta().isAdmin;
    const status = this.status;
    const running = this.job && (this.job.status === 'running' || this.job.status === 'pending');
    const noticeColor = this.notice === 'ok' ? 'green' : 'red';
    return html`<div class="paper" style="max-width:860px">
      <h3 class="title mb">${t('gsc.settings.title')}</h3>
      <div class="stack-sm">
        ${!isAdmin ? alertBox(t('settings.adminOnly'), { color: 'yellow' }) : nothing}
        ${this.error ? alertBox(this.error.message) : nothing}
        ${this.field('gsc_oauth_client_id', t('gsc.settings.clientId'), { placeholder: '….apps.googleusercontent.com' })}
        ${this.field('gsc_oauth_client_secret', t('gsc.settings.clientSecret'), {
          type: 'password',
          placeholder: this.values.gsc_oauth_client_secret_set ? t('settings.secretSet') : t('gsc.settings.clientSecret.placeholder'),
        })}
        <div class="field">
          <label>${t('gsc.settings.redirectUri')}</label>
          <span class="description">${t('gsc.settings.redirectUri.description')}</span>
          <code style="padding:8px 10px;word-break:break-all">${this.values.oauth_redirect_uri}</code>
        </div>
        ${this.field('gsc_site_url', t('gsc.settings.property'), { description: t('gsc.settings.property.description'), placeholder: 'sc-domain:example.com' })}
        ${this.field('gsc_brand_terms', t('gsc.settings.brandTerms'), { description: t('gsc.settings.brandTerms.description'), placeholder: t('settings.commaSeparated') })}
        ${this.field('gsc_snapshot_top_n', t('gsc.settings.snapshotTopN'), { type: 'number', description: t('gsc.settings.snapshotTopN.description') })}
        ${this.field('gsc_top_n', t('gsc.settings.topN'), { type: 'number', description: t('gsc.settings.topN.description') })}
        ${this.field('gsc_sitemap_url', t('gsc.settings.sitemap'), { description: t('gsc.settings.sitemap.description'), placeholder: this.values.gsc_sitemap_url_default || 'https://example.com/sitemap.xml' })}
        ${this.field('gsc_extra_source_urls', t('gsc.settings.extraSources'), { description: t('gsc.settings.extraSources.description'), placeholder: 'https://example.com/products' })}
        ${this.field('gsc_extra_include', t('gsc.settings.extraInclude'), { description: t('gsc.settings.extraInclude.description'), placeholder: '/products/' })}
        ${isAdmin ? html`<div>${spinnerButton(this.saved ? t('settings.saved') : t('settings.save'), {
          loading: this.saving, color: this.saved ? 'success' : '', onClick: () => this.save(),
        })}</div>` : nothing}

        <div class="paper alt mt">
          <div class="group between mb-sm">
            <span class="fw6">${t('gsc.settings.connection')}</span>
            ${status ? badge(status.connected ? t('gsc.settings.connected') : t('gsc.settings.notConnected'), status.connected ? 'green' : 'red') : nothing}
          </div>
          ${this.notice ? html`<div class="mb-sm">${alertBox(t(`gsc.oauth.${this.notice}`), { color: noticeColor })}</div>` : nothing}
          ${status?.error ? html`<div class="mb-sm">${alertBox(status.error, { color: 'yellow' })}</div>` : nothing}
          ${status && !status.connected ? html`
            ${isAdmin ? html`<button type="button" class="btn" ?disabled=${!status.configured} @click=${() => this.connect()}>${icon('google', 16)}${t('gsc.settings.connect')}</button>` : nothing}
            ${!status.configured ? html`<div class="sm dimmed mt">${t('gsc.settings.configureFirst')}</div>` : nothing}` : nothing}
          ${status?.connected ? html`
            <div class="sm mb-sm">${t('gsc.settings.properties')}</div>
            <ul class="list mb">${(status.sites ?? []).map((s) => html`<li>${s.siteUrl} <span class="dimmed">(${s.permissionLevel})</span></li>`)}</ul>
            ${isAdmin ? html`<button type="button" class="btn danger" @click=${() => this.disconnect()}>${t('gsc.settings.disconnect')}</button>` : nothing}` : nothing}
        </div>

        ${status?.connected ? html`<div class="paper alt mt">
          <div class="fw6 mb-sm">${t('gsc.settings.backfill')}</div>
          <div class="sm dimmed mb">${t('gsc.settings.backfill.description')}</div>
          <div class="group end">
            <div class="field" style="width:120px">
              <label for="f-days">${t('gsc.settings.backfill.days')}</label>
              <input id="f-days" class="input" type="number" min="1" max="90" .value=${String(this.backfillDays)} @input=${(e) => { this.backfillDays = e.target.value; }}>
            </div>
            ${spinnerButton(t('gsc.settings.backfill.start'), { loading: running, onClick: () => this.runner.start('backfill', { days: Number(this.backfillDays) || 90 }).catch((e) => { this.error = e; }) })}
          </div>
          ${this.job ? html`<div class="stack-xs mt">
            ${running && this.job.total ? html`<div class="progress"><div style="width:${Math.round((this.job.done / this.job.total) * 100)}%"></div></div>` : nothing}
            <span class="sm ${this.job.status === 'error' ? 'c-red' : (this.job.status === 'done' ? 'c-green' : 'dimmed')}">${jobProgressText(this.job)}</span>
          </div>` : nothing}
          <div class="xs dimmed mt">${t('gsc.settings.scheduler')}</div>
        </div>` : nothing}
      </div>
    </div>`;
  }
}

customElements.define('si-gsc', SiGsc);
customElements.define('si-gsc-appearance', SiGscAppearance);
customElements.define('si-gsc-visibility', SiGscVisibility);
customElements.define('si-gsc-cannibalization', SiGscCannibalization);
customElements.define('si-gsc-drilldown', SiGscDrilldown);
customElements.define('si-gsc-tracker-list', SiGscTrackerList);
customElements.define('si-gsc-tracker-detail', SiGscTrackerDetail);
customElements.define('si-gsc-index', SiGscIndex);
customElements.define('si-gsc-settings', SiGscSettings);
