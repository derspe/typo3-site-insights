/**
 * Matomo tiles of the page details, in one row below the pageviews chart:
 * <si-page-devices>: device shares of the page next to those of the whole site.
 * <si-page-events>: events on the page over time; expanded a tree category → action → name.
 * <si-page-goals>: goal conversions as entry page and on the way; expanded per goal.
 *
 * Each tile loads its data from "endpoint" with "params" like the other parts of the page
 * details. The host takes no box of its own (display: contents): tile and details are items
 * of the grid around the tiles, so expanded details span the whole row below the tiles.
 */
import { css, html, nothing } from 'lit';
import { SiElement, delta, deltaText, fmt, icon, skeleton, t } from '@derspe/site-insights/core.js';
import { SiLoading, softError } from '@derspe/site-insights/page-loading.js';
import '@derspe/site-insights/chart.js';

const tileStyles = [
  ...SiElement.styles,
  css`
    :host { display: contents; font-size: 12px; }
    .tile, .details { min-width: 0; padding: 10px 12px; border: 1px solid var(--si-border); border-radius: var(--si-radius);
      background: var(--si-surface); transition: opacity .15s; }
    :host([reloading]) .tile, :host([reloading]) .details { opacity: .55; }
    /* Below the row of tiles; on narrow screens right below its own tile */
    .details { grid-column: 1 / -1; order: 1; }
    @media (max-width: 760px) {
      .details { order: 0; }
    }
    .label { margin-bottom: 6px; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .02em; color: var(--si-dimmed); }
    .value { font-size: 20px; font-weight: 700; line-height: 1.3; color: var(--si-text-strong); font-variant-numeric: tabular-nums; }
    .figure { display: flex; align-items: baseline; flex-wrap: wrap; gap: 0 6px; }
    .toggle { display: inline-flex; align-items: center; gap: 4px; margin-top: 8px; padding: 0; border: 0; background: none;
      color: var(--si-primary-light-color); font-size: 12px; cursor: pointer; }
    .toggle:hover { text-decoration: underline; }
    .chevron { display: inline-flex; transition: transform .12s; }
    [aria-expanded="false"] > .chevron { transform: rotate(-90deg); }
    .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
  `,
];

function toggle(expanded, onClick) {
  return html`<button type="button" class="toggle" aria-expanded=${expanded ? 'true' : 'false'} @click=${onClick}>
    <span class="chevron">${icon('chevronDown', 14)}</span>${t(expanded ? 'pageDetail.hideDetails' : 'pageDetail.showDetails')}</button>`;
}

function hint(key) {
  return html`<div class="dimmed">${t(key)}</div>`;
}

// ---------------------------------------------------------------------------
// Devices
// ---------------------------------------------------------------------------

const DEVICES = { mobile: 'indigo', desktop: 'teal', tablet: 'orange', other: 'gray' };

class SiPageDevices extends SiLoading {
  static styles = [
    ...tileStyles,
    css`
      .bar { display: flex; overflow: hidden; border-radius: 3px; background: var(--si-hover); }
      /* Same height as the tiles with a chart: the site bar sits at the bottom, the rows share the rest */
      .tile { display: flex; flex-direction: column; }
      ul { flex: 1; display: flex; flex-direction: column; justify-content: space-evenly; margin: 10px 0; padding: 0; list-style: none; }
      .bar.page { height: 14px; }
      .bar.site { flex: 1; height: 6px; }
      .bar span { height: 100%; }
      .site-row { display: flex; align-items: center; gap: 8px; padding-top: 8px; border-top: 1px solid var(--si-border); }
      li { display: flex; align-items: center; gap: 6px; padding: 1px 0; }
      .dot { width: 8px; height: 8px; border-radius: 50%; flex: none; }
      .share { margin-left: auto; color: var(--si-text-strong); }
      .pp { min-width: 44px; }
    `,
  ];

  render() {
    return html`<div class="tile"><div class="label">${t('pageDetail.devices')}</div>${this.content()}</div>`;
  }

  content() {
    if (this.error) {
      return softError(this.error);
    }
    const d = this.data;
    if (!d) {
      return skeleton(110);
    }
    if (d.available === false) {
      return hint('pageDetail.segmentsUnavailable');
    }
    if (!d.visits) {
      return hint('empty.noData');
    }
    const kinds = Object.keys(DEVICES).filter((k) => d.page[k] > 0 || d.site[k] > 0);
    // The site bar comes last and apart, so the shares listed below the page bar are read as those of the page
    return html`
      ${this.bar(d.page, 'page', kinds)}
      <ul>
        ${kinds.map((k) => html`<li>
          <span class="dot" style="background:var(--si-${DEVICES[k]}-6)"></span>${t(`pageDetail.devices.${k}`)}
          <span class="share num">${fmt.pct(d.page[k], 0)}</span>
          ${d.compare ? html`<span class="pp num xs fw6">${this.change(d.page[k], d.compare[k])}</span>` : nothing}
        </li>`)}
      </ul>
      <div class="site-row"><span class="xs dimmed">${t('pageDetail.devices.site')}</span>${this.bar(d.site, 'site', kinds)}</div>`;
  }

  bar(shares, kind, kinds) {
    return html`<div class="bar ${kind}">
      ${kinds.filter((k) => shares[k] > 0).map((k) => html`<span style="width:${shares[k] * 100}%;background:var(--si-${DEVICES[k]}-6)"
        title="${t(`pageDetail.devices.${k}`)}: ${fmt.pct(shares[k], 1)}"></span>`)}
    </div>`;
  }

  /**
   * Change of a share in percentage points.
   */
  change(share, before) {
    const points = Math.round((share - (before ?? 0)) * 100);
    if (points === 0) {
      return html`<span class="dimmed">±0 Pp</span>`;
    }
    return deltaText(`${points > 0 ? '+' : ''}${fmt.int(points)} Pp`, points > 0);
  }
}

// ---------------------------------------------------------------------------
// Events
// ---------------------------------------------------------------------------

const byCount = (a, b) => b.count - a.count;

class SiPageEvents extends SiLoading {
  static properties = {
    expanded: { state: true },
    open: { state: true },
    names: { state: true },
  };

  static styles = [
    ...tileStyles,
    css`
      .per100 { margin-bottom: 6px; }
      .tree { display: flex; flex-direction: column; }
      .row { display: flex; align-items: center; gap: 4px; width: 100%; padding: 3px 0; border: 0; border-bottom: 1px solid var(--si-border);
        background: none; color: var(--si-text); font: inherit; font-size: 12px; text-align: left; }
      button.row { cursor: pointer; }
      button.row:hover { background: var(--si-hover); }
      .row .count { margin-left: auto; padding-left: 12px; }
      .row.category { font-weight: 600; color: var(--si-text-strong); }
      .row.action { padding-left: 18px; }
      .row.name { padding-left: 54px; color: var(--si-dimmed); }
      .row.leaf { padding-left: 36px; }
      .pending { padding: 4px 0 4px 36px; }
    `,
  ];

  constructor() {
    super();
    this.expanded = false;
    this.open = new Set();
    this.names = new Map();
  }

  willUpdate(changed) {
    const before = this.requestKey;
    super.willUpdate(changed);
    if (this.requestKey !== before) {
      this.open = new Set();
      this.names = new Map();
    }
  }

  /**
   * A page without events gets no tile at all; "empty" lets the tile row close the gap.
   */
  isEmpty() {
    const d = this.data;
    return !this.error && !!d && d.available !== false && !d.total;
  }

  updated() {
    this.toggleAttribute('empty', this.isEmpty());
  }

  render() {
    if (this.isEmpty()) {
      return nothing;
    }
    const d = this.data;
    const details = this.expanded && d?.available !== false && d?.total > 0;
    return html`<div class="tile"><div class="label">${t('pageDetail.events')}</div>${this.content()}</div>
      ${details ? html`<div class="details"><div class="label">${t('pageDetail.events')}</div>${this.tree(d.categories)}</div>` : nothing}`;
  }

  content() {
    if (this.error) {
      return softError(this.error);
    }
    const d = this.data;
    if (!d) {
      return skeleton(110);
    }
    if (d.available === false) {
      return hint('pageDetail.segmentsUnavailable');
    }
    const c = d.compare;
    const label = t('pageDetail.events');
    const series = [{ key: 'value', label, color: 'indigo' }];
    if (d.compareRange) {
      series.push({ key: 'compare', label: `${label} ${t('compare.previousPeriod')}`, color: 'indigo', shade: 3 });
    }
    return html`
      <div class="figure"><span class="value">${fmt.int(d.total)}</span>${c ? delta(d.total, c.total) : nothing}</div>
      <div class="figure per100 dimmed">
        <span><b class="num">${d.per100 == null ? '–' : fmt.dec(d.per100, 1)}</b> ${t('pageDetail.events.per100')}</span>
        ${c ? delta(d.per100, c.per100) : nothing}
      </div>
      <si-chart type="area" height="80" .data=${d.series} .series=${series}></si-chart>
      ${d.period === 'week' ? html`<div class="xs dimmed">${t('pageDetail.events.perWeek')}</div>` : nothing}
      ${toggle(this.expanded, () => { this.expanded = !this.expanded; })}`;
  }

  tree(categories) {
    return html`<div class="tree">${[...categories].sort(byCount).map((category) => {
      const key = category.label;
      const open = this.open.has(key);
      return html`
        <button type="button" class="row category" aria-expanded=${open ? 'true' : 'false'} @click=${() => this.toggleNode(key, category.label)}>
          <span class="chevron">${icon('chevronDown', 14)}</span>${category.label}<span class="count num">${fmt.int(category.count)}</span>
        </button>
        ${open ? this.actions(category) : nothing}`;
    })}</div>`;
  }

  /**
   * Actions of an open category with the names loaded for it; until they are there the actions
   * show without names.
   */
  actions(category) {
    const loaded = this.names.get(category.label);
    const named = new Map((loaded?.actions ?? []).map((a) => [a.label, a.names ?? []]));
    return html`
      ${[...category.actions].sort(byCount).map((action) => {
        const names = named.get(action.label) ?? [];
        if (names.length === 0) {
          return html`<div class="row action leaf"><span>${action.label}</span><span class="count num">${fmt.int(action.count)}</span></div>`;
        }
        const key = `${category.label}\u0000${action.label}`;
        const open = !this.open.has(key);
        return html`
          <button type="button" class="row action" aria-expanded=${open ? 'true' : 'false'} @click=${() => this.toggleKey(key)}>
            <span class="chevron">${icon('chevronDown', 14)}</span>${action.label}<span class="count num">${fmt.int(action.count)}</span>
          </button>
          ${open ? [...names].sort(byCount).map((name) => html`<div class="row name"><span>${name.label}</span><span class="count num">${fmt.int(name.count)}</span></div>`) : nothing}`;
      })}
      ${loaded == null ? html`<div class="pending">${skeleton(12, '40%')}</div>` : nothing}
      ${loaded?.error ? html`<div class="pending">${softError(loaded.error)}</div>` : nothing}`;
  }

  /**
   * Opens or closes a category; opening loads its event names unless they are there already.
   */
  toggleNode(key, category) {
    const opening = !this.open.has(key);
    this.toggleKey(key);
    // A failed request stays visible while the category is open and is repeated on the next opening
    if (!opening || (this.names.has(category) && !this.names.get(category).error)) {
      return;
    }
    if (this.names.has(category)) {
      const names = new Map(this.names);
      names.delete(category);
      this.names = names;
    }
    const requestKey = this.requestKey;
    const { url, start, end, segment } = this.params;
    this.api('matomo/page-event-names', { params: { url, start, end, segment, category } })
      .then((data) => data, (error) => ({ actions: [], error }))
      .then((result) => {
        if (requestKey !== this.requestKey) {
          return;
        }
        this.names = new Map(this.names).set(category, result);
      });
  }

  /**
   * Categories are closed and actions open by default; "open" holds the ones toggled.
   */
  toggleKey(key) {
    const open = new Set(this.open);
    if (!open.delete(key)) {
      open.add(key);
    }
    this.open = open;
  }
}

// ---------------------------------------------------------------------------
// Goals
// ---------------------------------------------------------------------------

class SiPageGoals extends SiLoading {
  static properties = {
    expanded: { state: true },
  };

  static styles = [
    ...tileStyles,
    css`
      .figure + .figure { margin-top: 2px; }
      .figure .dimmed { font-size: 12px; }
      table { width: 100%; border-collapse: collapse; }
      th, td { padding: 4px 8px; border-bottom: 1px solid var(--si-border); text-align: left; vertical-align: baseline; }
      th:first-child, td:first-child { padding-left: 0; }
      th:last-child, td:last-child { padding-right: 0; }
      th { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .02em; color: var(--si-dimmed); }
      td .xs { margin-left: 6px; }
      .foot { margin-top: 6px; }
      .legend { display: flex; flex-wrap: wrap; gap: 2px 12px; margin: 2px 0 6px; }
      .legend .dot { display: inline-block; width: 8px; height: 8px; margin-right: 4px; border-radius: 50%; }
      .bar { display: flex; height: 8px; overflow: hidden; border-radius: 3px; background: var(--si-hover); }
      .bar span { height: 100%; }
      td.bar-cell { width: 40%; vertical-align: middle; }
    `,
  ];

  constructor() {
    super();
    this.expanded = false;
  }

  render() {
    const d = this.data;
    const details = this.expanded && d?.defined && d.goals.length > 0;
    return html`<div class="tile"><div class="label">${t('pageDetail.goals')}</div>${this.content()}</div>
      ${details ? html`<div class="details">${this.table(d)}</div>` : nothing}`;
  }

  content() {
    if (this.error) {
      return softError(this.error);
    }
    const d = this.data;
    if (!d) {
      return skeleton(110);
    }
    if (!d.defined) {
      return hint('pageDetail.noGoals');
    }
    const c = d.compareTotals;
    // Chart first, like the bar of the devices tile; entry on top of the lighter "on the way"
    const series = [
      { key: 'onTheWay', label: t('pageDetail.goals.onTheWay'), color: 'green', shade: 3 },
      { key: 'entry', label: t('pageDetail.goals.entry'), color: 'green' },
    ];
    if (d.compareRange) {
      series.push({ key: 'compare', label: t('compare.previousPeriod'), color: 'gray', shade: 3 });
    }
    return html`
      ${d.series.length > 0 ? html`<si-chart type="area" height="80" .data=${d.series} .series=${series}></si-chart>
        <div class="legend xs dimmed">${series.map((x) => html`<span><span class="dot" style="background:var(--si-${x.color}-${x.shade ?? 6})"></span>${x.label}</span>`)}</div>
        ${d.period === 'week' ? html`<div class="xs dimmed">${t('pageDetail.goals.perWeek')}</div>` : nothing}` : nothing}
      <div title=${t('pageDetail.goals.hint')}>
        <div class="figure"><span class="value">${fmt.int(d.totals.entry)}</span>
          <span class="dimmed">${t('pageDetail.goals.entry')}</span>${c ? delta(d.totals.entry, c.entry) : nothing}</div>
        <div class="figure"><span class="value">${fmt.int(d.totals.onTheWay)}</span>
          <span class="dimmed">${t('pageDetail.goals.onTheWay')}</span>${c ? delta(d.totals.onTheWay, c.onTheWay) : nothing}</div>
      </div>
      ${d.goals.length > 0 ? toggle(this.expanded, () => { this.expanded = !this.expanded; }) : nothing}`;
  }

  table(d) {
    const goals = [...d.goals].sort((a, b) => (b.entry + b.onTheWay) - (a.entry + a.onTheWay));
    const cell = (value, before) => html`<td class="num">${fmt.int(value)}${before == null ? nothing : delta(value, before)}</td>`;
    const max = Math.max(1, ...goals.map((g) => g.entry + g.onTheWay));
    const bar = (g) => html`<div class="bar">
      <span style="width:${(g.entry / max) * 100}%;background:var(--si-green-6)"></span>
      <span style="width:${(g.onTheWay / max) * 100}%;background:var(--si-green-3)"></span>
    </div>`;
    return html`
      <table>
        <thead><tr>
          <th>${t('pageDetail.goals')}</th>
          <th></th>
          <th class="num">${t('pageDetail.goals.entry')}</th>
          <th class="num">${t('pageDetail.goals.onTheWay')}</th>
        </tr></thead>
        <tbody>${goals.map((g) => html`<tr>
          <td>${g.name}</td><td class="bar-cell">${g.entry + g.onTheWay > 0 ? bar(g) : nothing}</td>${cell(g.entry, g.compare?.entry)}${cell(g.onTheWay, g.compare?.onTheWay)}
        </tr>`)}</tbody>
      </table>
      <div class="xs dimmed foot">${t('pageDetail.goals.hint')}</div>`;
  }
}

customElements.define('si-page-devices', SiPageDevices);
customElements.define('si-page-events', SiPageEvents);
customElements.define('si-page-goals', SiPageGoals);
