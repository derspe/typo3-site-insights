/**
 * <derspe-site-insights-news site="…" folder="…" language="…">: key figures of all news records of a
 * folder in the page and list module. A click on a row shows the search queries of the record.
 */
import { css, html, nothing } from 'lit';
import { SiElement, alertBox, delta, errorState, fmt, icon, loadMeta, segmented, skeleton, statCard, t } from '@derspe/site-insights/core.js';
import '@derspe/site-insights/table.js';

const STORAGE_KEY = 'derspe-site-insights-news';
const PAGE_SIZE = 25;

function readOpen() {
  try {
    return window.localStorage.getItem(STORAGE_KEY) !== 'closed';
  } catch {
    return true;
  }
}

function writeOpen(open) {
  try {
    window.localStorage.setItem(STORAGE_KEY, open ? 'open' : 'closed');
  } catch {
    // storage not available
  }
}

class SiteInsightsNews extends SiElement {
  static properties = {
    folder: { type: Number },
    language: { type: Number },
    matomo: { type: Boolean },
    gsc: { type: Boolean },
    data: { state: true },
    error: { state: true },
    open: { state: true },
    filter: { state: true },
    search: { state: true },
    sortKey: { state: true },
    sortDir: { state: true },
    showAll: { state: true },
    expanded: { state: true },
    queries: { state: true },
  };

  static styles = [
    ...SiElement.styles,
    css`
      :host { display: block; margin: 0 0 16px; font-size: 13px; }
      .box { background: var(--si-surface); border: 1px solid var(--si-border); border-radius: var(--si-radius); }
      .head { display: flex; align-items: center; gap: 8px; padding: 10px 12px; cursor: pointer; user-select: none; }
      .head .chevron { display: inline-flex; margin-left: auto; transition: transform .15s; }
      .box.open .head .chevron { transform: rotate(180deg); }
      .body { padding: 0 12px 12px; }
      .grid-tight { grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); }
      .toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin: 12px 0 8px; }
      .toolbar input { max-width: 260px; }
      .wrap { overflow-x: auto; }
      table { width: 100%; border-collapse: collapse; font-size: 12px; }
      th, td { padding: 7px 10px; text-align: left; border-bottom: 1px solid var(--si-border); vertical-align: top; }
      th { font-weight: 600; color: var(--si-text-strong); white-space: nowrap; cursor: pointer; user-select: none; }
      th .inner { display: inline-flex; align-items: center; gap: 2px; }
      th .icon { opacity: .4; }
      th.active .icon { opacity: 1; }
      .right { text-align: right; }
      td.num { text-align: right; white-space: nowrap; }
      td.num .xs { display: block; }
      tbody tr.row { cursor: pointer; }
      tbody tr.row:hover { background: var(--si-hover); }
      tbody tr.row.expanded { background: var(--si-primary-light); }
      .title { display: flex; align-items: flex-start; gap: 6px; }
      .title .text { font-weight: 600; color: var(--si-text-strong); }
      .title a { display: inline-flex; color: var(--si-text); opacity: .6; }
      .title a:hover { opacity: 1; color: var(--si-primary); }
      td.detail { background: var(--si-surface-alt); padding: 10px 12px; }
      .footer { margin-top: 6px; }
      .more { margin-top: 10px; }
    `,
  ];

  constructor() {
    super();
    this.folder = 0;
    this.language = 0;
    this.matomo = false;
    this.gsc = false;
    this.data = null;
    this.error = null;
    this.open = readOpen();
    this.filter = 'all';
    this.search = '';
    this.sortKey = '';
    this.sortDir = 'desc';
    this.showAll = false;
    this.expanded = 0;
    this.queries = {};
  }

  connectedCallback() {
    super.connectedCallback();
    loadMeta()
      .then(() => this.api('news/overview', { params: { folder: this.folder, language: this.language, returnUrl: window.location.href } }))
      .then((data) => {
        this.data = data;
        this.sortKey = data.gsc?.configured === false ? 'pageviews' : 'clicks';
      })
      .catch((e) => { this.error = e; });
  }

  toggle() {
    this.open = !this.open;
    writeOpen(this.open);
  }

  render() {
    const count = this.data?.records?.length;
    return html`<div class="box ${this.open ? 'open' : ''}">
      <div class="head" role="button" tabindex="0" aria-expanded=${this.open ? 'true' : 'false'}
        @click=${() => this.toggle()} @keydown=${(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); this.toggle(); } }}>
        ${icon('chartBar', 16, 'var(--si-primary)')}
        <span class="fw6">${t('news.title')}</span>
        ${count != null ? html`<span class="xs dimmed">${t('news.count', { count })} · ${t('widget.last28')}</span>` : nothing}
        <span class="chevron">${icon('chevronDown', 14)}</span>
      </div>
      ${this.open ? html`<div class="body">${this.content()}</div>` : nothing}
    </div>`;
  }

  content() {
    if (this.error) {
      return errorState(this.error);
    }
    const d = this.data;
    if (!d) {
      const cards = (this.matomo ? 2 : 0) + (this.gsc ? 3 : 0);
      return html`<div class="stack-sm">
        <div class="grid-tight">${Array.from({ length: cards }, () => html`<div class="stat compact">${skeleton(40)}</div>`)}</div>
        ${skeleton(160)}
      </div>`;
    }
    if (!d.detailPage) {
      return alertBox(t('news.noDetailPage'), { color: 'yellow' });
    }
    const m = this.source('matomo');
    const g = this.source('gsc');
    return html`
      ${m.error ? html`<div class="mb-sm">${alertBox(`Matomo: ${m.error}`)}</div>` : nothing}
      ${g.error ? html`<div class="mb-sm">${alertBox(`Search Console: ${g.error}`)}</div>` : nothing}
      ${this.totals(m, g)}
      ${this.table(m, g)}
      ${d.truncated ? html`<div class="xs dimmed footer">${t('news.truncated', { count: d.records.length })}</div>` : nothing}
      <div class="xs dimmed footer">${this.footer(m, g)}</div>`;
  }

  /**
   * Source state: configured (false = hidden), error, ok (figures available).
   */
  source(name) {
    const s = this.data[name] ?? { configured: false };
    const configured = s.configured !== false;
    return { ...s, configured, ok: configured && !s.error };
  }

  totals(m, g) {
    const tm = this.data.totals.matomo;
    const tg = this.data.totals.gsc;
    const cards = [];
    if (m.ok) {
      cards.push(
        statCard({ compact: true, label: t('matomo.pageviews'), value: fmt.int(tm.pageviews), raw: tm.pageviews, compare: tm.compare.pageviews, color: 'indigo', icon: 'eye' }),
        statCard({ compact: true, label: t('page.entries'), value: fmt.int(tm.entries), raw: tm.entries, compare: tm.compare.entries, color: 'orange', icon: 'targetArrow' }),
      );
    }
    if (g.ok) {
      const position = tg.impressions > 0 ? tg.position : null;
      const compare = tg.compare.impressions > 0 ? tg.compare.position : null;
      cards.push(
        statCard({ compact: true, label: t('metric.clicks'), value: fmt.int(tg.clicks), raw: tg.clicks, compare: tg.compare.clicks, color: 'green', icon: 'mouse' }),
        statCard({ compact: true, label: t('metric.impressions'), value: fmt.int(tg.impressions), raw: tg.impressions, compare: tg.compare.impressions, color: 'blue', icon: 'search' }),
        statCard({ compact: true, label: t('metric.avgPosition'), value: fmt.dec(position), raw: position, compare, invert: true, color: 'teal', icon: 'number' }),
      );
    }
    return cards.length ? html`<div class="grid-tight">${cards}</div>` : nothing;
  }

  /**
   * Columns of the sources with figures; value() returns the current value, compare() the one before.
   */
  columns(m, g) {
    const columns = [];
    if (m.ok) {
      columns.push(
        { key: 'pageviews', label: t('matomo.pageviews'), value: (r) => r.matomo.pageviews, compare: (r) => r.matomo.compare.pageviews, format: fmt.int },
        { key: 'entries', label: t('page.entries'), value: (r) => r.matomo.entries, compare: (r) => r.matomo.compare.entries, format: fmt.int },
      );
    }
    if (g.ok) {
      columns.push(
        { key: 'clicks', label: t('metric.clicks'), value: (r) => r.gsc.clicks, compare: (r) => r.gsc.compare.clicks, format: fmt.int },
        { key: 'impressions', label: t('metric.impressions'), value: (r) => r.gsc.impressions, compare: (r) => r.gsc.compare.impressions, format: fmt.int },
        {
          key: 'position',
          label: t('metric.positionShort'),
          // Without impressions there is no position
          value: (r) => (r.gsc.impressions > 0 ? r.gsc.position : null),
          compare: (r) => (r.gsc.compare.impressions > 0 ? r.gsc.compare.position : null),
          format: (v) => fmt.dec(v),
          invert: true,
        },
      );
    }
    return columns;
  }

  /**
   * Main figure of the "declining" filter: clicks, or pageviews without Search Console.
   */
  trend(g) {
    return g.ok
      ? { value: (r) => r.gsc.clicks, compare: (r) => r.gsc.compare.clicks }
      : { value: (r) => r.matomo.pageviews, compare: (r) => r.matomo.compare.pageviews };
  }

  filtered(m, g) {
    const term = this.search.trim().toLowerCase();
    let rows = this.data.records.filter((r) => !term || r.title.toLowerCase().includes(term));
    const trend = this.trend(g);
    if (this.filter === 'noImpressions') {
      rows = rows.filter((r) => r.gsc && r.gsc.impressions === 0);
    } else if (this.filter === 'declining') {
      rows = rows.filter((r) => trend.value(r) < trend.compare(r));
    }
    return rows;
  }

  sorted(rows, columns, g) {
    if (this.filter === 'declining' && !this.sortKey) {
      const trend = this.trend(g);
      return [...rows].sort((a, b) => (trend.value(a) - trend.compare(a)) - (trend.value(b) - trend.compare(b)));
    }
    const dir = this.sortDir === 'asc' ? 1 : -1;
    if (this.sortKey === 'title') {
      return [...rows].sort((a, b) => a.title.localeCompare(b.title) * dir);
    }
    if (this.sortKey === 'date') {
      return [...rows].sort((a, b) => (a.datetime - b.datetime) * dir);
    }
    const column = columns.find((c) => c.key === this.sortKey);
    if (!column) {
      return rows;
    }
    return [...rows].sort((a, b) => ((column.value(a) ?? -Infinity) - (column.value(b) ?? -Infinity) || 0) * dir);
  }

  sortBy(key) {
    if (key === this.sortKey) {
      this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
    } else {
      this.sortKey = key;
      this.sortDir = key === 'title' ? 'asc' : 'desc';
    }
  }

  setFilter(filter) {
    this.filter = filter;
    this.showAll = false;
    // "Declining" is sorted by the largest loss first
    this.sortKey = filter === 'declining' ? '' : (this.sortKey || (this.source('gsc').ok ? 'clicks' : 'pageviews'));
  }

  table(m, g) {
    const columns = this.columns(m, g);
    const all = this.data.records;
    const trend = this.trend(g);
    const filters = [{ value: 'all', label: `${t('news.filter.all')} (${all.length})` }];
    if (g.ok) {
      filters.push({ value: 'noImpressions', label: `${t('news.filter.noImpressions')} (${all.filter((r) => r.gsc.impressions === 0).length})` });
    }
    if (m.ok || g.ok) {
      filters.push({ value: 'declining', label: `${t('news.filter.declining')} (${all.filter((r) => trend.value(r) < trend.compare(r)).length})` });
    }
    const rows = this.sorted(this.filtered(m, g), columns, g);
    const limited = this.showAll ? rows : rows.slice(0, PAGE_SIZE);
    const header = (key, label, right = false) => {
      const active = key === this.sortKey;
      const sortIcon = active ? (this.sortDir === 'asc' ? 'chevronUp' : 'chevronDown') : 'selector';
      return html`<th class="${right ? 'right' : ''} ${active ? 'active' : ''}" @click=${() => this.sortBy(key)}>
        <span class="inner">${label}${icon(sortIcon, 11)}</span></th>`;
    };
    return html`
      <div class="toolbar">
        ${filters.length > 1 ? segmented(filters.map((f) => ({ ...f, active: f.value === this.filter })), (f) => this.setFilter(f.value)) : nothing}
        <input class="input xs" type="search" placeholder=${t('news.search')} aria-label=${t('news.search')}
          .value=${this.search} @input=${(e) => { this.search = e.target.value; this.showAll = false; }}>
        ${g.ok ? html`<span class="xs dimmed ml-auto">${t('news.queriesHint')}</span>` : nothing}
      </div>
      ${rows.length === 0 ? html`<div class="xs dimmed">${t('news.empty')}</div>` : html`
        <div class="wrap">
          <table>
            <thead><tr>
              ${header('title', t('news.column.title'))}
              ${header('date', t('news.column.date'))}
              ${columns.map((c) => header(c.key, c.label, true))}
            </tr></thead>
            <tbody>${limited.map((r) => this.row(r, columns, g))}</tbody>
          </table>
        </div>
        ${rows.length > PAGE_SIZE ? html`<div class="more"><button type="button" class="btn subtle xs full" @click=${() => { this.showAll = !this.showAll; }}>
          ${this.showAll ? t('table.showLess') : t('table.showAll', { count: rows.length })}</button></div>` : nothing}`}`;
  }

  row(r, columns, g) {
    const expanded = this.expanded === r.uid;
    const expandable = g.ok && r.url;
    return html`
      <tr class="${expandable ? 'row' : ''} ${expanded ? 'expanded' : ''}" @click=${() => expandable && this.expand(r)}>
        <td>
          <div class="title">
            <span class="text">${r.title}</span>
            <a href=${r.editUrl} title=${t('news.edit')} aria-label=${t('news.edit')} @click=${(e) => e.stopPropagation()}>${icon('pencil', 14)}</a>
            ${r.url ? html`<a href=${r.url} target="_blank" rel="noopener noreferrer" title=${t('news.view')} aria-label=${t('news.view')}
              @click=${(e) => e.stopPropagation()}>${icon('externalLink', 14)}</a>` : nothing}
          </div>
          ${r.url ? nothing : html`<span class="xs dimmed">${t('news.noUrl')}</span>`}
        </td>
        <td style="white-space:nowrap">${r.datetime ? fmt.date(new Date(r.datetime * 1000).toISOString()) : '–'}</td>
        ${columns.map((c) => html`<td class="num">${c.format(c.value(r))}${delta(c.value(r), c.compare(r), c.invert)}</td>`)}
      </tr>
      ${expanded ? html`<tr><td class="detail" colspan=${2 + columns.length}>${this.queriesOf(r)}</td></tr>` : nothing}`;
  }

  expand(r) {
    this.expanded = this.expanded === r.uid ? 0 : r.uid;
    if (this.expanded && !this.queries[r.uid]) {
      this.queries = { ...this.queries, [r.uid]: { loading: true } };
      this.api('news/queries', { params: { folder: this.folder, uid: r.uid, language: this.language } })
        .then((data) => { this.queries = { ...this.queries, [r.uid]: { data } }; })
        .catch((error) => { this.queries = { ...this.queries, [r.uid]: { error } }; });
    }
  }

  queriesOf(r) {
    const state = this.queries[r.uid];
    if (!state || state.loading) {
      return skeleton(80);
    }
    if (state.error) {
      return errorState(state.error);
    }
    const q = state.data;
    return html`
      <div class="fw6 mb-sm">${t('page.topQueries', { count: q.queries.length })}</div>
      ${q.queries.length === 0
        ? html`<div class="xs dimmed">${t('empty.noData')}</div>`
        : html`<si-table sort-key="clicks" .rows=${q.queries} .columns=${[
          { key: 'query', label: t('metric.query') },
          { key: 'clicks', label: t('metric.clicks'), align: 'right', render: (x) => fmt.int(x.clicks) },
          { key: 'impressions', label: t('metric.impressions'), align: 'right', render: (x) => fmt.int(x.impressions) },
          { key: 'ctr', label: t('metric.ctr'), align: 'right', render: (x) => fmt.pct(x.ctr) },
          { key: 'position', label: t('metric.positionShort'), align: 'right', render: (x) => fmt.dec(x.position) },
        ]}></si-table>`}
      ${q.anonymized ? html`<div class="xs footer">${t('page.anonymized', { clicks: fmt.int(q.anonymized.clicks), impressions: fmt.int(q.anonymized.impressions) })}</div>` : nothing}
      <div class="xs dimmed footer">${t('page.anonymizedHint')}</div>`;
  }

  footer(m, g) {
    const parts = [t('widget.last28'), t('widget.vsPrevious')];
    if (m.ok && m.range) {
      parts.push(`Matomo ${fmt.date(m.range.startDate)} – ${fmt.date(m.range.endDate)}`);
    }
    if (g.ok && g.range) {
      parts.push(`Search Console ${fmt.date(g.range.startDate)} – ${fmt.date(g.range.endDate)}`);
    }
    return parts.join(' · ');
  }
}

customElements.define('derspe-site-insights-news', SiteInsightsNews);
