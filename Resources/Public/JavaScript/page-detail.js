/**
 * Details of one page:
 * <si-transitions>: Matomo transitions as a flow – where visitors came from and where they went next.
 * <si-page-queries>: Search Console figures and top queries.
 * <si-page-snippet>: title, description and URL of the page or news record as the head of the modal.
 * <si-page-detail>: modal of the backend module with both, for a page clicked in a report.
 *
 * The first two load their data themselves from "endpoint" with "params"; a change of either reloads.
 */
import { css, html, nothing, svg } from 'lit';
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

function clip(text, length) {
  return text.length > length ? `${text.slice(0, length - 1)}…` : text;
}

// ---------------------------------------------------------------------------
// Transitions flow
// ---------------------------------------------------------------------------

const KINDS = {
  search: 'green',
  direct: 'gray',
  website: 'orange',
  campaign: 'grape',
  social: 'pink',
  ai: 'cyan',
  other: 'gray',
  page: 'indigo',
  siteSearch: 'teal',
  loop: 'yellow',
  outlink: 'orange',
  download: 'cyan',
  exit: 'red',
  others: 'gray',
};

// Entries per side, the rest is grouped as "Others"
const MAX_ITEMS = 7;

// Geometry in viewBox units
const W = 820;
const BOX = { x: 340, w: 140, h: 200 };
const NODE_W = 6;
const LEFT_NODE = BOX.x - 84;
const RIGHT_NODE = BOX.x + BOX.w + 78;
const SLOT_MIN = 24;
const GAP = 6;
const PAD = 8;

function sum(rows) {
  return (rows ?? []).reduce((s, r) => s + r.count, 0);
}

function listTip(rows, label = (r) => r.label) {
  return rows.slice(0, 15).map((r) => `${label(r)}: ${fmt.int(r.count)}`).join('\n');
}

/**
 * Single entries plus aggregated groups, largest first, the tail grouped as "Others".
 */
function sideItems(single, groups) {
  const items = [...single, ...groups.filter((g) => g.count > 0)].sort((a, b) => b.count - a.count);
  if (items.length <= MAX_ITEMS) {
    return items;
  }
  const grouped = items.slice(MAX_ITEMS - 1);
  return [...items.slice(0, MAX_ITEMS - 1), { label: t('transitions.others'), kind: 'others', count: sum(grouped), tip: listTip(grouped) }];
}

function incoming(d) {
  const single = [
    ...d.referrers.map((r) => ({ label: r.label, kind: r.kind ?? 'other', count: r.count, tip: listTip(r.details ?? []) })),
    ...d.previousPages.filter((r) => !r.others).map((r) => ({ label: pagePath(r.label), kind: 'page', count: r.count, url: r.label })),
  ];
  const otherPages = d.previousPages.filter((r) => r.others);
  return sideItems(single, [
    { label: t('transitions.previousSearches'), kind: 'siteSearch', count: sum(d.previousSiteSearches), tip: listTip(d.previousSiteSearches) },
    { label: t('transitions.loops'), kind: 'loop', count: d.loops, tip: t('transitions.loops.hint') },
    { label: t('transitions.otherPages'), kind: 'others', count: sum(otherPages) },
  ]);
}

function outgoing(d) {
  const single = d.followingPages.filter((r) => !r.others).map((r) => ({ label: pagePath(r.label), kind: 'page', count: r.count, url: r.label }));
  const outlinks = sum(d.outlinks);
  const downloads = sum(d.downloads);
  return sideItems(single, [
    { label: t('transitions.followingSearches'), kind: 'siteSearch', count: sum(d.followingSiteSearches), tip: listTip(d.followingSiteSearches) },
    { label: t('transitions.outlinks'), kind: 'outlink', count: outlinks, tip: listTip(d.outlinks, (r) => r.label.replace(/^https?:\/\//, '')) },
    { label: t('transitions.downloads'), kind: 'download', count: downloads, tip: listTip(d.downloads, (r) => pagePath(r.label)) },
    // Matomo counts outlinks and downloads as exits too
    { label: t('transitions.exits'), kind: 'exit', count: Math.max(0, d.exits - outlinks - downloads), tip: t('transitions.exits.hint') },
    { label: t('transitions.loops'), kind: 'loop', count: d.loops, tip: t('transitions.loops.hint') },
    { label: t('transitions.otherPages'), kind: 'others', count: sum(d.followingPages.filter((r) => r.others)) },
  ]);
}

/**
 * Vertical positions: each entry gets a slot (at least one text line), the bands meet the box stacked.
 */
function layout(items) {
  const total = Math.max(1, sum(items));
  const placed = items.map((item) => {
    const band = (item.count / total) * BOX.h;
    const node = Math.max(band, 3);
    return { ...item, band, node, slot: Math.max(node, SLOT_MIN) };
  });
  const column = placed.reduce((s, p) => s + p.slot, 0) + GAP * Math.max(0, placed.length - 1);
  return { placed, column };
}

class SiTransitions extends SiLoading {
  static properties = {
    navigable: { type: Boolean },
  };

  static styles = [
    ...SiElement.styles,
    css`
      :host { font-size: 12px; }
      svg { display: block; width: 100%; max-width: ${W}px; margin: 0 auto; overflow: visible; }
      text { fill: var(--si-text); font-size: 12px; dominant-baseline: middle; }
      .pct { font-weight: 700; fill: var(--si-text-strong); font-variant-numeric: tabular-nums; }
      .band { fill-opacity: .25; transition: fill-opacity .12s; }
      .flow:hover .band { fill-opacity: .5; }
      .flow:hover text { fill: var(--si-text-strong); }
      .flow.link { cursor: pointer; }
      .flow.link:hover .label { text-decoration: underline; }
      .box { fill: var(--si-surface-alt); stroke: var(--si-border); }
      .box-title { font-weight: 600; fill: var(--si-text-strong); }
      .box-value { font-size: 24px; font-weight: 700; fill: var(--si-indigo-6); }
      .box-sub { font-size: 11px; fill: var(--si-dimmed); }
      .metrics { display: flex; flex-wrap: wrap; gap: 6px 20px; max-width: ${W}px; margin: 0 auto 12px; }
      .metrics b { color: var(--si-text-strong); font-size: 14px; }
      .heads { display: flex; justify-content: space-between; max-width: ${W}px; margin: 0 auto 4px; }
      .heads span { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .02em; color: var(--si-dimmed); }
      .foot { max-width: ${W}px; margin: 8px auto 0; display: flex; flex-wrap: wrap; gap: 4px 16px; align-items: center; }
      .dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 4px; }
    `,
  ];

  constructor() {
    super();
    this.navigable = false;
  }

  render() {
    if (this.error) {
      return softError(this.error);
    }
    const d = this.data;
    if (!d) {
      return skeleton(220);
    }
    if (d.configured === false) {
      return html`<div class="dimmed">${t('transitions.notConfigured')}</div>`;
    }
    if (d.noData || !d.pageviews) {
      return html`<div class="dimmed">${t('transitions.noData')}</div>`;
    }
    const left = layout(incoming(d));
    const right = layout(outgoing(d));
    const height = Math.max(left.column, right.column, BOX.h) + 2 * PAD;
    const boxY = (height - BOX.h) / 2;
    const share = (n) => fmt.pct(n / d.pageviews, 0);
    return html`
      <div class="metrics">
        <span>${t('transitions.avgTimeOnPage')}: <b>${fmt.duration(d.avgTimeOnPage)}</b></span>
        <span title=${t('transitions.bounceRate.hint')}>${t('matomo.bounceRate')}: <b>${d.bounceRate == null ? '–' : fmt.pct(d.bounceRate, 0)}</b></span>
        <span>${t('page.entries')}: <b>${fmt.int(d.entries)}</b> <span class="dimmed">(${share(d.entries)})</span></span>
        <span>${t('transitions.exitRate')}: <b>${share(d.exits)}</b></span>
      </div>
      <div class="heads"><span>${t('transitions.before')}</span><span>${t('transitions.after')}</span></div>
      <svg viewBox="0 0 ${W} ${height}" role="img" aria-label=${t('transitions.title')}>
        ${this.side(left, 'left', height, boxY, d.pageviews)}
        ${this.side(right, 'right', height, boxY, d.pageviews)}
        <rect class="box" x=${BOX.x} y=${boxY} width=${BOX.w} height=${BOX.h} rx="6"></rect>
        <text class="box-title" x=${BOX.x + BOX.w / 2} y=${boxY + 22} text-anchor="middle">
          ${clip(pagePath(d.url), 20)}<title>${d.url}</title></text>
        <text class="box-value" x=${BOX.x + BOX.w / 2} y=${boxY + BOX.h / 2} text-anchor="middle">${fmt.int(d.pageviews)}</text>
        <text class="box-sub" x=${BOX.x + BOX.w / 2} y=${boxY + BOX.h / 2 + 22} text-anchor="middle">${t('matomo.pageviews')}</text>
      </svg>
      <div class="foot xs dimmed">
        ${this.legend([...left.placed, ...right.placed])}
        <span class="ml-auto">${t('transitions.hint', { start: fmt.date(d.range.startDate), end: fmt.date(d.range.endDate) })}</span>
      </div>`;
  }

  side({ placed, column }, side, height, boxY, pageviews) {
    let y = (height - column) / 2;
    let stacked = boxY;
    return placed.map((item) => {
      const center = y + item.slot / 2;
      const nodeX = side === 'left' ? LEFT_NODE : RIGHT_NODE;
      const atNode = center - item.band / 2;
      const atBox = stacked;
      y += item.slot + GAP;
      stacked += item.band;
      const [x0, a, x1, b] = side === 'left'
        ? [LEFT_NODE + NODE_W, atNode, BOX.x, atBox]
        : [BOX.x + BOX.w, atBox, RIGHT_NODE, atNode];
      const mx = (x0 + x1) / 2;
      const color = `var(--si-${KINDS[item.kind] ?? 'gray'}-6)`;
      const pct = fmt.pct(item.count / pageviews, 0);
      const link = this.navigable && item.url;
      const tip = `${item.label}: ${fmt.int(item.count)} (${pct})${item.tip ? `\n\n${item.tip}` : ''}`;
      return svg`<g class="flow ${link ? 'link' : ''}" @click=${link ? () => this.navigate(item.url) : null}>
        <title>${tip}</title>
        <path class="band" fill=${color}
          d="M${x0} ${a} C${mx} ${a} ${mx} ${b} ${x1} ${b} L${x1} ${b + item.band} C${mx} ${b + item.band} ${mx} ${a + item.band} ${x0} ${a + item.band} Z"></path>
        <rect x=${nodeX} y=${center - item.node / 2} width=${NODE_W} height=${item.node} rx="2" fill=${color}></rect>
        ${side === 'left'
          ? svg`<text class="pct" x=${nodeX - 8} y=${center} text-anchor="end">${pct}</text>
            <text class="label" x=${nodeX - 50} y=${center} text-anchor="end">${clip(item.label, 30)}</text>`
          : svg`<text class="pct" x=${nodeX + NODE_W + 8} y=${center}>${pct}</text>
            <text class="label" x=${nodeX + NODE_W + 50} y=${center}>${clip(item.label, 30)}</text>`}
      </g>`;
    });
  }

  legend(items) {
    const groups = [
      ['transitions.legend.external', ['search', 'direct', 'website', 'campaign', 'social', 'ai', 'other']],
      ['transitions.legend.internal', ['page', 'siteSearch', 'loop']],
      ['transitions.legend.leaving', ['exit', 'outlink', 'download']],
    ];
    const kinds = new Set(items.map((i) => i.kind));
    return groups.filter(([, list]) => list.some((k) => kinds.has(k))).map(([label, list]) => html`<span>
      ${list.filter((k) => kinds.has(k)).map((k) => html`<span class="dot" style="background:var(--si-${KINDS[k]}-6)"></span>`)}${t(label)}</span>`);
  }

  navigate(url) {
    this.dispatchEvent(new CustomEvent('si-navigate', { detail: { url: /^https?:\/\//.test(url) ? url : `https://${url}` }, bubbles: true, composed: true }));
  }
}

// ---------------------------------------------------------------------------
// Search Console queries of a page
// ---------------------------------------------------------------------------

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
        <span class="dimmed">${fmt.date(d.range.startDate)} – ${fmt.date(d.range.endDate)}</span>
      </div>
      ${!d.supportsQuery ? html`<div class="dimmed">${t('pageDetail.noQueries')}</div>`
        : d.queries.length === 0 ? html`<div class="dimmed">${t('empty.noData')}</div>`
        : html`<si-table sort-key="clicks" page-size="10" .rows=${d.queries} .columns=${[
          { key: 'query', label: t('metric.query'), maxWidth: 360 },
          { key: 'clicks', label: t('metric.clicks'), align: 'right', render: (r) => fmt.int(r.clicks) },
          { key: 'impressions', label: t('metric.impressionsShort'), align: 'right', render: (r) => fmt.int(r.impressions) },
          { key: 'ctr', label: t('metric.ctr'), align: 'right', render: (r) => fmt.pct(r.ctr) },
          { key: 'position', label: t('metric.positionShort'), align: 'right', render: (r) => fmt.dec(r.position) },
        ]}></si-table>`}
      ${d.anonymized && (d.anonymized.clicks || d.anonymized.impressions) ? html`<div class="xs dimmed" style="margin-top:6px">
        ${t('page.anonymized', { clicks: fmt.int(d.anonymized.clicks), impressions: fmt.int(d.anonymized.impressions) })}</div>` : nothing}`;
  }
}

// ---------------------------------------------------------------------------
// Snippet
// ---------------------------------------------------------------------------

/**
 * Title and description as entered by the editors; "fallback" is shown as title when the URL
 * has no record (e.g. a page of another system) or while loading.
 */
class SiPageSnippet extends SiLoading {
  static properties = {
    ...SiLoading.properties,
    url: { type: String },
    fallback: { type: String },
  };

  static styles = [
    ...SiElement.styles,
    css`
      .snippet { max-width: 600px; padding-top: 2px; }
      .headline { margin: 0; color: var(--si-text-strong); font-size: 18px; font-weight: 600; line-height: 1.35; letter-spacing: -.005em;
        text-wrap: balance; overflow-wrap: anywhere; }
      .desc { margin: 6px 0 0; color: var(--si-text); font-size: 14px; line-height: 1.55; text-wrap: pretty; overflow-wrap: anywhere; }
      .warn { display: inline-flex; align-items: center; gap: 6px; color: var(--si-orange-6); font-size: 13px; }
      .url { margin-top: 6px; color: var(--si-dimmed); font-size: 12px; line-height: 1.4; overflow-wrap: anywhere; }
    `,
  ];

  constructor() {
    super();
    this.url = '';
    this.fallback = '';
    this.endpoint = 'page/snippet';
  }

  willUpdate(changed) {
    if (changed.has('url')) {
      this.params = { url: this.url };
    }
    super.willUpdate(changed);
  }

  render() {
    const d = this.data;
    const loading = !d && !this.error;
    return html`<div class="snippet">
      <h2 class="headline">${d?.title || this.fallback || fmt.path(this.url)}</h2>
      ${loading ? html`<div class="desc">${skeleton(14)}</div>`
        : d ? html`<p class="desc">${d.description || html`<span class="warn">${icon('alertCircle', 14)}${t('pageDetail.noDescription')}</span>`}</p>`
        : nothing}
      <div class="url">${this.url}</div>
    </div>`;
  }
}

// ---------------------------------------------------------------------------
// Modal
// ---------------------------------------------------------------------------

/**
 * Modal with the Matomo transitions and the Search Console queries of a page in the period of
 * the report it was opened from; Matomo always comes first. "url" may be an absolute URL or a
 * path. "matomo-start"/"matomo-end" give Matomo its own period, "no-gsc" and
 * "no-matomo" hide a source that is not set up, "heading" replaces the path as title.
 * A click on an internal page in the flow opens that page.
 */
class SiPageDetail extends SiElement {
  static properties = {
    url: { type: String },
    start: { type: String },
    end: { type: String },
    searchType: { type: String, attribute: 'search-type' },
    segment: { type: String },
    matomoStart: { type: String, attribute: 'matomo-start' },
    matomoEnd: { type: String, attribute: 'matomo-end' },
    noGsc: { type: Boolean, attribute: 'no-gsc' },
    noMatomo: { type: Boolean, attribute: 'no-matomo' },
    heading: { type: String },
    trail: { state: true },
  };

  static styles = [
    ...SiElement.styles,
    css`
      dialog { width: min(980px, calc(100vw - 32px)); max-height: calc(100vh - 48px); padding: 0; overflow: auto;
        border: 1px solid var(--si-border); border-radius: var(--si-radius); background: var(--si-surface); color: var(--si-text);
        box-shadow: 0 12px 48px rgba(0, 0, 0, .3); }
      dialog::backdrop { background: rgba(0, 0, 0, .45); }
      .head { position: sticky; top: 0; z-index: 1; display: flex; align-items: flex-start; gap: 8px; padding: 12px 16px;
        background: var(--si-surface); border-bottom: 1px solid var(--si-border); }
      .head a { color: var(--si-dimmed); display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; }
      .body { padding: 16px; }
      section + section { margin-top: 24px; }
      .icon-btn { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; flex-shrink: 0;
        border: 0; border-radius: var(--si-radius-sm); background: transparent; color: var(--si-dimmed); cursor: pointer; }
      .icon-btn:hover { background: var(--si-hover); color: var(--si-text); }
      .back { transform: rotate(90deg); }
    `,
  ];

  constructor() {
    super();
    this.url = '';
    this.start = '';
    this.end = '';
    this.searchType = 'web';
    this.segment = '';
    this.matomoStart = '';
    this.matomoEnd = '';
    this.noGsc = false;
    this.noMatomo = false;
    this.heading = '';
    this.trail = [];
  }

  firstUpdated() {
    this.renderRoot.querySelector('dialog').showModal();
  }

  close() {
    this.renderRoot.querySelector('dialog').close();
  }

  open(url) {
    this.trail = [...this.trail, this.url];
    this.url = url;
  }

  back() {
    this.url = this.trail[this.trail.length - 1];
    this.trail = this.trail.slice(0, -1);
  }

  render() {
    const sources = getMeta().sources ?? {};
    const sections = [];
    if (sources.matomo !== false && !this.noMatomo) {
      sections.push(html`<section>
        <div class="section-head">${icon('route', 15, 'var(--si-indigo-6)')}${t('transitions.title')}</div>
        <si-transitions .site=${this.site} endpoint="matomo/transitions" navigable @si-navigate=${(e) => this.open(e.detail.url)}
          .params=${{ url: this.url, start: this.matomoStart || this.start, end: this.matomoEnd || this.end, segment: this.segment }}></si-transitions>
      </section>`);
    }
    if (sources.gsc !== false && !this.noGsc) {
      sections.push(html`<section>
        <div class="section-head">${icon('google', 15, 'var(--si-blue-6)')}${t('pageDetail.queries')}</div>
        <si-page-queries .site=${this.site} endpoint="gsc/page"
          .params=${{ url: this.url, start: this.start, end: this.end, searchType: this.searchType }}></si-page-queries>
      </section>`);
    }
    const absolute = /^https?:\/\//.test(this.url);
    return html`<dialog aria-label=${this.heading || fmt.path(this.url)}
      @close=${() => this.dispatchEvent(new CustomEvent('close'))}
      @click=${(e) => { if (e.target === e.currentTarget) { this.close(); } }}>
      <div class="head">
        ${this.trail.length ? html`<button type="button" class="icon-btn" title=${t('pageDetail.back')} aria-label=${t('pageDetail.back')}
          @click=${() => this.back()}><span class="back">${icon('chevronDown', 16)}</span></button>` : nothing}
        <si-page-snippet class="grow" style="min-width:0" .site=${this.site} url=${this.url}
          fallback=${this.heading && !this.trail.length ? this.heading : ''}></si-page-snippet>
        ${absolute ? html`<a href=${this.url} target="_blank" rel="noopener noreferrer" title=${t('news.view')}>${icon('externalLink', 14)}</a>` : nothing}
        <button type="button" class="icon-btn" title=${t('pageDetail.close')} aria-label=${t('pageDetail.close')}
          @click=${() => this.close()}>${icon('x', 16)}</button>
      </div>
      <div class="body">${sections}</div>
    </dialog>`;
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
customElements.define('si-page-snippet', SiPageSnippet);
customElements.define('si-page-detail', SiPageDetail);
