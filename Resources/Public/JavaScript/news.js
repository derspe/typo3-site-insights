/**
 * <derspe-site-insights-news site="…" folder="…" language="…">: key figures of all news records of a
 * folder in the page and list module. The totals are always visible, the list of records can be
 * expanded; a click on a row opens the details of the record (search queries, visitor paths).
 */
import { css, html, nothing } from 'lit';
import { SiElement, alertBox, delta, errorState, fmt, icon, loadMeta, segmented, skeleton, statCard, t } from '@derspe/site-insights/core.js';
import '@derspe/site-insights/page-detail.js';

const STORAGE_KEY = 'derspe-site-insights-news-list';
const PAGE_SIZE = 25;

function readOpen() {
  try {
    return window.localStorage.getItem(STORAGE_KEY) === 'open';
  } catch {
    return false;
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
    detail: { state: true },
  };

  static styles = [
    ...SiElement.styles,
    css`
      :host { display: block; margin: 0 0 16px; font-size: 13px; }
      .box { background: var(--si-surface); border: 1px solid var(--si-border); border-radius: var(--si-radius); }
      .head { display: flex; align-items: center; gap: 8px; padding: 10px 12px; }
      .body { padding: 0 12px 12px; }
      .list-toggle { display: flex; align-items: center; gap: 6px; width: 100%; margin-top: 10px; padding: 8px 10px; border: 1px solid var(--si-border);
        border-radius: var(--si-radius); background: var(--si-surface-alt); color: var(--si-text-strong); font: inherit; font-weight: 600; cursor: pointer; }
      .list-toggle:hover { background: var(--si-hover); }
      .list-toggle .chevron { display: inline-flex; transition: transform .15s; }
      .list-toggle.open .chevron { transform: rotate(180deg); }
      .grid-tight { grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); }
      .toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin: 12px 0 8px; }
      .toolbar input { max-width: 260px; }
      .wrap { overflow-x: auto; }
      table { width: 100%; border-collapse: collapse; font-size: 12px; }
      th, td { padding: 7px 10px; text-align: left; border-bottom: 1px solid var(--si-border); vertical-align: top; }
      th { font-weight: 600; color: var(--si-text-strong); white-space: nowrap; user-select: none; }
      th .sort { display: inline-flex; align-items: center; gap: 2px; cursor: pointer; }
      th .sort + .sort { margin-left: 8px; font-weight: 500; color: var(--si-text); }
      th .sort .icon { opacity: .4; }
      th .sort.active { font-weight: 700; color: var(--si-text-strong); }
      th .sort.active .icon { opacity: 1; }
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
    this.detail = null;
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
    return html`<div class="box">
      <div class="head">
        ${icon('chartBar', 16, 'var(--si-primary)')}
        <span class="fw6">${t('news.title')}</span>
        ${count != null ? html`<span class="xs dimmed">${t('news.count', { count })} · ${t('widget.last28')}</span>` : nothing}
      </div>
      <div class="body">${this.content()}</div>
    </div>`;
  }

  content() {
    if (this.error) {
      return errorState(this.error);
    }
    const d = this.data;
    if (!d) {
      const cards = (this.matomo ? 2 : 0) + (this.gsc ? 3 : 0);
      return html`<div class="grid-tight">${Array.from({ length: cards }, () => html`<div class="stat compact">${skeleton(40)}</div>`)}</div>`;
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
      <div class="xs dimmed footer">${this.footer(m, g)}</div>
      <button type="button" class="list-toggle ${this.open ? 'open' : ''}" aria-expanded=${this.open ? 'true' : 'false'} @click=${() => this.toggle()}>
        <span class="chevron">${icon('chevronDown', 14)}</span>${t('news.list', { count: d.records.length })}
      </button>
      ${this.open ? html`
        ${this.table(m, g)}
        ${d.truncated ? html`<div class="xs dimmed footer">${t('news.truncated', { count: d.records.length })}</div>` : nothing}` : nothing}
      ${this.renderDetail(m, g)}`;
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
        statCard({ compact: true, label: t('matomo.pageviews'), value: fmt.int(tm.pageviews), raw: tm.pageviews, compare: tm.compare.pageviews, color: 'indigo', icon: 'eye', source: 'matomo' }),
        statCard({ compact: true, label: t('page.entries'), value: fmt.int(tm.entries), raw: tm.entries, compare: tm.compare.entries, color: 'orange', icon: 'targetArrow', source: 'matomo' }),
      );
    }
    if (g.ok) {
      const position = tg.impressions > 0 ? tg.position : null;
      const compare = tg.compare.impressions > 0 ? tg.compare.position : null;
      cards.push(
        statCard({ compact: true, label: t('metric.clicks'), value: fmt.int(tg.clicks), raw: tg.clicks, compare: tg.compare.clicks, color: 'green', icon: 'mouse', source: 'gsc' }),
        statCard({ compact: true, label: t('metric.impressions'), value: fmt.int(tg.impressions), raw: tg.impressions, compare: tg.compare.impressions, color: 'blue', icon: 'search', source: 'gsc' }),
        statCard({ compact: true, label: t('metric.avgPosition'), value: fmt.dec(position), raw: position, compare, invert: true, color: 'teal', icon: 'number', source: 'gsc' }),
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

  filtered() {
    const term = this.search.trim().toLowerCase();
    let rows = this.data.records.filter((r) => !term || r.title.toLowerCase().includes(term));
    if (this.filter === 'noImpressions') {
      rows = rows.filter((r) => r.gsc && r.gsc.impressions === 0);
    }
    return rows;
  }

  /**
   * Absolute change against the period before; negative is always worse (a higher position is worse).
   */
  static change(column, row) {
    const value = column.value(row);
    const compare = column.compare(row);
    if (value == null || compare == null) {
      return null;
    }
    return column.invert ? compare - value : value - compare;
  }

  sorted(rows, columns) {
    const dir = this.sortDir === 'asc' ? 1 : -1;
    if (this.sortKey === 'title') {
      return [...rows].sort((a, b) => a.title.localeCompare(b.title) * dir);
    }
    if (this.sortKey === 'date') {
      return [...rows].sort((a, b) => (a.datetime - b.datetime) * dir);
    }
    const [key, mode] = this.sortKey.split(':');
    const column = columns.find((c) => c.key === key);
    if (!column) {
      return rows;
    }
    const value = mode === 'change' ? (r) => SiteInsightsNews.change(column, r) : column.value;
    // Records without a value are always last
    return [...rows].sort((a, b) => {
      const av = value(a);
      const bv = value(b);
      if (av == null || bv == null) {
        return (av == null) - (bv == null);
      }
      return (av - bv) * dir;
    });
  }

  sortBy(key) {
    if (key === this.sortKey) {
      this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
    } else {
      this.sortKey = key;
      // Changes start with the largest loss
      this.sortDir = key === 'title' || key.endsWith(':change') ? 'asc' : 'desc';
    }
  }

  setFilter(filter) {
    this.filter = filter;
    this.showAll = false;
  }

  table(m, g) {
    const columns = this.columns(m, g);
    const all = this.data.records;
    const filters = [{ value: 'all', label: `${t('news.filter.all')} (${all.length})` }];
    if (g.ok) {
      filters.push({ value: 'noImpressions', label: `${t('news.filter.noImpressions')} (${all.filter((r) => r.gsc.impressions === 0).length})` });
    }
    const rows = this.sorted(this.filtered(), columns);
    const limited = this.showAll ? rows : rows.slice(0, PAGE_SIZE);
    const sortIcon = (key) => icon(key === this.sortKey ? (this.sortDir === 'asc' ? 'chevronUp' : 'chevronDown') : 'selector', 11);
    const target = (key, content, title = nothing) => html`<span class="inner sort ${key === this.sortKey ? 'active' : ''}" title=${title}
      @click=${() => this.sortBy(key)}>${content}${sortIcon(key)}</span>`;
    const header = (key, label, right = false) => html`<th class=${right ? 'right' : ''}>
      ${target(key, label)}
      ${right ? target(`${key}:change`, 'Δ', t('news.sortByChange')) : nothing}
    </th>`;
    return html`
      <div class="toolbar">
        ${filters.length > 1 ? segmented(filters.map((f) => ({ ...f, active: f.value === this.filter })), (f) => this.setFilter(f.value)) : nothing}
        <input class="input xs" type="search" placeholder=${t('news.search')} aria-label=${t('news.search')}
          .value=${this.search} @input=${(e) => { this.search = e.target.value; this.showAll = false; }}>
        ${g.ok || m.ok ? html`<span class="xs dimmed ml-auto">${t(!m.ok ? 'pageDetail.hint.gsc' : !g.ok ? 'pageDetail.hint.matomo' : 'pageDetail.hint')}</span>` : nothing}
      </div>
      ${rows.length === 0 ? html`<div class="xs dimmed">${t('news.empty')}</div>` : html`
        <div class="wrap">
          <table>
            <thead><tr>
              ${header('title', t('news.column.title'))}
              ${header('date', t('news.column.date'))}
              ${columns.map((c) => header(c.key, c.label, true))}
            </tr></thead>
            <tbody>${limited.map((r) => this.row(r, columns, g, m))}</tbody>
          </table>
        </div>
        ${rows.length > PAGE_SIZE ? html`<div class="more"><button type="button" class="btn subtle xs full" @click=${() => { this.showAll = !this.showAll; }}>
          ${this.showAll ? t('table.showLess') : t('table.showAll', { count: rows.length })}</button></div>` : nothing}`}`;
  }

  row(r, columns, g, m) {
    const openable = (g.ok || m.ok) && r.url;
    return html`
      <tr class="${openable ? 'row' : ''} ${this.detail?.uid === r.uid ? 'expanded' : ''}" @click=${() => openable && this.openDetail(r)}>
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
      </tr>`;
  }

  openDetail(r) {
    this.detail = r;
  }

  /**
   * Modal of the selected record in the periods of the overview.
   */
  renderDetail(m, g) {
    const r = this.detail;
    if (!r) {
      return nothing;
    }
    const gscRange = g.range ?? m.range;
    const matomoRange = m.range ?? g.range;
    return html`<si-page-detail .site=${this.site} url=${r.url} heading=${r.title} primary=${g.ok ? 'gsc' : 'matomo'}
      start=${gscRange.startDate} end=${gscRange.endDate} matomo-start=${matomoRange.startDate} matomo-end=${matomoRange.endDate}
      ?no-gsc=${!g.ok} ?no-matomo=${!m.ok} @close=${() => { this.detail = null; }}></si-page-detail>`;
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
