/**
 * Details of one page:
 * <si-transitions>: Matomo transitions – where visitors came from and where they went next.
 * <si-page-queries>: Search Console figures and top queries.
 * <si-page-detail>: panel of the backend module with both, for a page clicked in a report.
 *
 * The first two load their data themselves from "endpoint" with "params"; a change of either reloads.
 */
import { css, html, nothing } from 'lit';
import { SiElement, errorState, fmt, getMeta, icon, skeleton, t } from '@derspe/site-insights/core.js';
import '@derspe/site-insights/table.js';

class SiLoading extends SiElement {
  static properties = {
    endpoint: { type: String },
    params: { attribute: false },
    data: { state: true },
    error: { state: true },
  };

  constructor() {
    super();
    this.endpoint = '';
    this.params = {};
    this.data = null;
    this.error = null;
    this.requestKey = '';
  }

  willUpdate(changed) {
    if (!(changed.has('site') || changed.has('endpoint') || changed.has('params')) || !this.site || !this.endpoint) {
      return;
    }
    const key = JSON.stringify([this.site, this.endpoint, this.params]);
    if (key === this.requestKey) {
      return;
    }
    this.requestKey = key;
    this.data = null;
    this.error = null;
    this.api(this.endpoint, { params: this.params })
      .then((data) => { if (key === this.requestKey) { this.data = data; } })
      .catch((error) => { if (key === this.requestKey) { this.error = error; } });
  }
}

/**
 * A source that is not set up is no error here, only a note.
 */
function softError(error) {
  return error.status === 503 ? html`<div class="dimmed">${error.message}</div>` : errorState(error);
}

/**
 * Matomo page labels have no protocol ("example.com/news/a"); internal pages are shown by their path.
 */
function pagePath(label) {
  const plain = String(label).replace(/^https?:\/\//, '');
  const slash = plain.indexOf('/');
  return slash > 0 && plain.slice(0, slash).includes('.') ? plain.slice(slash) : plain;
}

class SiTransitions extends SiLoading {
  static styles = [
    ...SiElement.styles,
    css`
      :host { font-size: 12px; }
      .metrics { display: flex; flex-wrap: wrap; gap: 4px 16px; margin-bottom: 10px; }
      .metrics b { color: var(--si-text-strong); }
      .cols { display: grid; gap: 16px; grid-template-columns: minmax(0, 1fr); }
      @media (min-width: 700px) { .cols { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
      .group + .group { margin-top: 10px; }
      .head { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .02em; color: var(--si-dimmed); margin-bottom: 4px; }
      .row { position: relative; display: flex; gap: 8px; align-items: baseline; padding: 3px 6px; border-radius: var(--si-radius-sm); }
      .row .bar { position: absolute; inset: 0 auto 0 0; border-radius: var(--si-radius-sm); background: var(--bar, var(--si-primary-light)); }
      .row > :not(.bar) { position: relative; }
      .row .label { flex: 1 1 auto; min-width: 0; }
      .row .count { white-space: nowrap; font-variant-numeric: tabular-nums; }
      .row .share { width: 46px; text-align: right; white-space: nowrap; color: var(--si-dimmed); font-variant-numeric: tabular-nums; }
      .details { color: var(--si-dimmed); padding: 0 6px 2px 16px; }
      .exit { --bar: light-dark(rgba(250, 82, 82, .12), rgba(250, 82, 82, .2)); font-weight: 600; }
    `,
  ];

  render() {
    if (this.error) {
      return softError(this.error);
    }
    const d = this.data;
    if (!d) {
      return skeleton(160);
    }
    if (d.configured === false) {
      return html`<div class="dimmed">${t('transitions.notConfigured')}</div>`;
    }
    if (d.noData || !d.pageviews) {
      return html`<div class="dimmed">${t('transitions.noData')}</div>`;
    }
    const share = (n) => fmt.pct(n / d.pageviews, 0);
    const before = [
      this.group(t('transitions.referrers'), d.referrers, d.pageviews, (r) => r.label, (r) => r.details),
      this.group(t('transitions.previousPages'), d.previousPages, d.pageviews, (r) => pagePath(r.label)),
      this.group(t('transitions.previousSearches'), d.previousSiteSearches, d.pageviews, (r) => r.label),
    ];
    const after = [
      this.group(t('transitions.followingPages'), d.followingPages, d.pageviews, (r) => pagePath(r.label)),
      this.group(t('transitions.followingSearches'), d.followingSiteSearches, d.pageviews, (r) => r.label),
      this.group(t('transitions.outlinks'), d.outlinks, d.pageviews, (r) => r.label.replace(/^https?:\/\//, '')),
      this.group(t('transitions.downloads'), d.downloads, d.pageviews, (r) => pagePath(r.label)),
      html`<div class="group">${this.row(t('transitions.exits'), d.exits, d.pageviews, 'exit')}</div>`,
    ];
    return html`
      <div class="metrics">
        <span>${t('matomo.pageviews')}: <b>${fmt.int(d.pageviews)}</b></span>
        <span>${t('page.entries')}: <b>${fmt.int(d.entries)}</b> (${share(d.entries)})</span>
        <span>${t('transitions.exits')}: <b>${fmt.int(d.exits)}</b> (${share(d.exits)})</span>
        ${d.loops ? html`<span title=${t('transitions.loops.hint')}>${t('transitions.loops')}: <b>${fmt.int(d.loops)}</b></span>` : nothing}
      </div>
      <div class="cols">
        <div><div class="head">${t('transitions.before')}</div>${before}</div>
        <div><div class="head">${t('transitions.after')}</div>${after}</div>
      </div>
      <div class="xs dimmed" style="margin-top:8px">${t('transitions.hint', { start: fmt.date(d.range.startDate), end: fmt.date(d.range.endDate) })}</div>`;
  }

  group(title, rows, total, label, details = null) {
    if (!rows?.length) {
      return nothing;
    }
    return html`<div class="group">
      <div class="xs fw6">${title}</div>
      ${rows.map((r) => html`${this.row(label(r), r.count, total, '', r.label)}
        ${details && details(r)?.length ? html`<div class="details truncate" title=${details(r).map((x) => `${x.label}: ${x.count}`).join('\n')}>
          ${details(r).slice(0, 4).map((x) => `${x.label} ${fmt.int(x.count)}`).join(' · ')}</div>` : nothing}`)}
    </div>`;
  }

  row(label, count, total, cls = '', title = '') {
    const ratio = total > 0 ? Math.min(1, count / total) : 0;
    return html`<div class="row ${cls}">
      <span class="bar" style="width:${(ratio * 100).toFixed(1)}%"></span>
      <span class="label truncate" title=${title || label}>${label}</span>
      <span class="count">${fmt.int(count)}</span>
      <span class="share">${fmt.pct(ratio, 0)}</span>
    </div>`;
  }
}

class SiPageQueries extends SiLoading {
  static styles = [
    ...SiElement.styles,
    css`
      :host { font-size: 12px; }
      .metrics { display: flex; flex-wrap: wrap; gap: 4px 16px; margin-bottom: 10px; }
      .metrics b { color: var(--si-text-strong); }
    `,
  ];

  render() {
    if (this.error) {
      return softError(this.error);
    }
    const d = this.data;
    if (!d) {
      return skeleton(160);
    }
    return html`
      <div class="metrics">
        <span>${t('metric.clicks')}: <b>${fmt.int(d.clicks)}</b></span>
        <span>${t('metric.impressions')}: <b>${fmt.int(d.impressions)}</b></span>
        <span>${t('metric.ctr')}: <b>${fmt.pct(d.ctr)}</b></span>
        <span>${t('metric.positionShort')}: <b>${d.impressions > 0 ? fmt.dec(d.position) : '–'}</b></span>
      </div>
      ${!d.supportsQuery ? html`<div class="dimmed">${t('pageDetail.noQueries')}</div>`
        : d.queries.length === 0 ? html`<div class="dimmed">${t('empty.noData')}</div>`
        : html`<si-table sort-key="clicks" page-size="10" .rows=${d.queries} .columns=${[
          { key: 'query', label: t('metric.query'), maxWidth: 220 },
          { key: 'clicks', label: t('metric.clicks'), align: 'right', render: (r) => fmt.int(r.clicks) },
          { key: 'impressions', label: t('metric.impressionsShort'), align: 'right', render: (r) => fmt.int(r.impressions) },
          { key: 'ctr', label: t('metric.ctr'), align: 'right', render: (r) => fmt.pct(r.ctr) },
          { key: 'position', label: t('metric.positionShort'), align: 'right', render: (r) => fmt.dec(r.position) },
        ]}></si-table>`}
      ${d.anonymized && (d.anonymized.clicks || d.anonymized.impressions) ? html`<div class="xs dimmed" style="margin-top:6px">
        ${t('page.anonymized', { clicks: fmt.int(d.anonymized.clicks), impressions: fmt.int(d.anonymized.impressions) })}</div>` : nothing}
      <div class="xs dimmed" style="margin-top:6px">${fmt.date(d.range.startDate)} – ${fmt.date(d.range.endDate)}</div>`;
  }
}

/**
 * Panel below a report: Search Console queries and Matomo transitions of the selected page in the
 * period of the report. "url" may be an absolute URL or a path.
 */
class SiPageDetail extends SiElement {
  static properties = {
    url: { type: String },
    start: { type: String },
    end: { type: String },
    searchType: { type: String, attribute: 'search-type' },
    segment: { type: String },
  };

  static styles = [
    ...SiElement.styles,
    css`
      .paper { border-color: var(--si-primary); }
      .top { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; }
      .top a { color: var(--si-dimmed); display: inline-flex; }
      .close { margin-left: auto; display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px;
        border: 0; border-radius: var(--si-radius-sm); background: transparent; color: var(--si-dimmed); cursor: pointer; }
      .close:hover { background: var(--si-hover); color: var(--si-text); }
    `,
  ];

  constructor() {
    super();
    this.url = '';
    this.start = '';
    this.end = '';
    this.searchType = 'web';
    this.segment = '';
  }

  updated(changed) {
    if (changed.has('url')) {
      this.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
  }

  render() {
    const sources = getMeta().sources ?? {};
    const parts = [];
    if (sources.gsc !== false) {
      parts.push(html`<div>
        <div class="section-head">${icon('google', 15, 'var(--si-blue-6)')}${t('pageDetail.queries')}</div>
        <si-page-queries .site=${this.site} endpoint="gsc/page"
          .params=${{ url: this.url, start: this.start, end: this.end, searchType: this.searchType }}></si-page-queries>
      </div>`);
    }
    if (sources.matomo !== false) {
      parts.push(html`<div>
        <div class="section-head">${icon('route', 15, 'var(--si-indigo-6)')}${t('transitions.title')}</div>
        <si-transitions .site=${this.site} endpoint="matomo/transitions"
          .params=${{ url: this.url, start: this.start, end: this.end, segment: this.segment }}></si-transitions>
      </div>`);
    }
    const absolute = /^https?:\/\//.test(this.url);
    return html`<div class="paper">
      <div class="top">
        <span class="fw7 truncate" title=${this.url}>${fmt.path(this.url)}</span>
        ${absolute ? html`<a href=${this.url} target="_blank" rel="noopener noreferrer" title=${t('news.view')}>${icon('externalLink', 14)}</a>` : nothing}
        <button type="button" class="close" title=${t('pageDetail.close')} aria-label=${t('pageDetail.close')}
          @click=${() => this.dispatchEvent(new CustomEvent('close'))}>${icon('x', 16)}</button>
      </div>
      <div class="grid ${parts.length > 1 ? 'cols-2' : ''}">${parts}</div>
    </div>`;
  }
}

/**
 * Hint below the title of a report whose rows open the page details.
 */
export function pageDetailHint() {
  const sources = getMeta().sources ?? {};
  const key = sources.gsc === false ? 'pageDetail.hint.matomo' : sources.matomo === false ? 'pageDetail.hint.gsc' : 'pageDetail.hint';
  return html`<div class="xs dimmed mb-sm">${t(key)}</div>`;
}

customElements.define('si-transitions', SiTransitions);
customElements.define('si-page-queries', SiPageQueries);
customElements.define('si-page-detail', SiPageDetail);
