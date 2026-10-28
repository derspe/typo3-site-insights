/**
 * <derspe-site-insights-page site="…" page="…" language="…">: key figures of one page in the page module.
 */
import { css, html, nothing } from 'lit';
import { SiElement, errorState, fmt, icon, loadMeta, skeleton, statCard, t } from '@derspe/site-insights/core.js';
import '@derspe/site-insights/table.js';
import '@derspe/site-insights/page-detail.js';

class SiteInsightsPage extends SiElement {
  static properties = {
    page: { type: Number },
    language: { type: Number },
    matomo: { type: Boolean },
    gsc: { type: Boolean },
    data: { state: true },
    error: { state: true },
    transitionsOpen: { state: true },
  };

  static styles = [
    ...SiElement.styles,
    css`
      :host { display: block; margin: 0 0 16px; font-size: 13px; }
      .grid-tight { grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); }
      .footer { margin-top: 6px; }
      details { margin-top: 10px; background: var(--si-surface); border: 1px solid var(--si-border); border-radius: var(--si-radius); }
      summary { cursor: pointer; padding: 8px 12px; font-weight: 600; list-style: none; display: flex; align-items: center; gap: 6px; }
      summary::-webkit-details-marker { display: none; }
      summary .chevron { display: inline-flex; transition: transform .15s; }
      details[open] summary .chevron { transform: rotate(180deg); }
      .queries, .transitions { padding: 0 12px 10px; }
    `,
  ];

  constructor() {
    super();
    this.page = 0;
    this.language = 0;
    this.matomo = false;
    this.gsc = false;
    this.data = null;
    this.error = null;
    this.transitionsOpen = false;
  }

  connectedCallback() {
    super.connectedCallback();
    loadMeta()
      .then(() => this.api('page/insights', { params: { page: this.page, language: this.language } }))
      .then((data) => { this.data = data; })
      .catch((e) => { this.error = e; });
  }

  render() {
    if (this.error) {
      return errorState(this.error);
    }
    const d = this.data;
    if (!d) {
      const count = (this.matomo ? 2 : 0) + (this.gsc ? 3 : 0);
      return this.grid(Array.from({ length: count }, () => html`<div class="stat compact">${skeleton(40)}</div>`));
    }
    const m = d.matomo;
    const g = d.gsc;
    const cards = [];
    if (m.configured !== false) {
      cards.push(
        this.card(m, 'pageviews', t('matomo.pageviews'), 'indigo', 'eye'),
        this.card(m, 'entries', t('page.entries'), 'orange', 'targetArrow'),
      );
    }
    if (g.configured !== false) {
      cards.push(
        this.card(g, 'clicks', t('metric.clicks'), 'green', 'mouse'),
        this.card(g, 'impressions', t('metric.impressions'), 'blue', 'search'),
        this.positionCard(g),
      );
    }
    if (cards.length === 0) {
      return nothing;
    }
    return html`
      ${this.grid(cards)}
      <div class="xs dimmed footer">${this.footer(m, g)}</div>
      ${g.configured === false || g.error ? nothing : this.queries(g)}
      ${m.configured === false || m.error ? nothing : this.transitions()}`;
  }

  grid(cards) {
    return html`<div class="grid-tight">${cards}</div>`;
  }

  unavailable(source, label, iconName) {
    return statCard({ compact: true, label, value: '–', color: 'gray', icon: iconName,
      sub: html`<div class="xs dimmed truncate" title=${source.error}>${t('page.unavailable')}</div>` });
  }

  card(source, key, label, color, iconName) {
    if (source.error) {
      return this.unavailable(source, label, iconName);
    }
    return statCard({ compact: true, label, value: fmt.int(source[key]), raw: source[key], compare: source.compare?.[key], color, icon: iconName });
  }

  positionCard(g) {
    const label = t('metric.avgPosition');
    if (g.error) {
      return this.unavailable(g, label, 'number');
    }
    // Without impressions there is no position
    const position = g.impressions > 0 ? g.position : null;
    const compare = g.compare?.impressions > 0 ? g.compare.position : null;
    return statCard({ compact: true, label, value: fmt.dec(position), raw: position, compare, invert: true, color: 'teal', icon: 'number' });
  }

  footer(m, g) {
    const parts = [t('widget.last28'), t('widget.vsPrevious')];
    if (m.configured !== false && !m.error) {
      parts.push(`Matomo ${fmt.date(m.range.startDate)} – ${fmt.date(m.range.endDate)}`);
    }
    if (g.configured !== false && !g.error) {
      parts.push(`Search Console ${fmt.date(g.range.startDate)} – ${fmt.date(g.range.endDate)}`);
    }
    return parts.join(' · ');
  }

  /**
   * Matomo computes transitions from its raw data on every request: only loaded once opened.
   */
  transitions() {
    return html`<details @toggle=${(e) => { this.transitionsOpen ||= e.target.open; }}>
      <summary><span class="chevron">${icon('chevronDown', 14)}</span>${t('transitions.title')}</summary>
      <div class="transitions">
        ${this.transitionsOpen ? html`<si-transitions .site=${this.site} endpoint="page/transitions"
          .params=${{ page: this.page, language: this.language }}></si-transitions>` : nothing}
      </div>
    </details>`;
  }

  queries(g) {
    return html`<details>
      <summary><span class="chevron">${icon('chevronDown', 14)}</span>${t('page.topQueries', { count: g.queries.length })}</summary>
      <div class="queries">
        ${g.queries.length === 0
          ? html`<div class="xs dimmed">${t('empty.noData')}</div>`
          : html`<si-table sort-key="clicks" .rows=${g.queries} .columns=${[
            { key: 'query', label: t('metric.query') },
            { key: 'clicks', label: t('metric.clicks'), align: 'right', render: (r) => fmt.int(r.clicks) },
            { key: 'impressions', label: t('metric.impressions'), align: 'right', render: (r) => fmt.int(r.impressions) },
            { key: 'ctr', label: t('metric.ctr'), align: 'right', render: (r) => fmt.pct(r.ctr) },
            { key: 'position', label: t('metric.positionShort'), align: 'right', render: (r) => fmt.dec(r.position) },
          ]}></si-table>`}
        ${g.anonymized ? html`<div class="xs footer">${t('page.anonymized', { clicks: fmt.int(g.anonymized.clicks), impressions: fmt.int(g.anonymized.impressions) })}</div>` : nothing}
        <div class="xs dimmed footer">${t('page.anonymizedHint')}</div>
      </div>
    </details>`;
  }
}

customElements.define('derspe-site-insights-page', SiteInsightsPage);
