/**
 * <derspe-site-insights-page site="…" page="…" language="…">: key figures of one page in the page module.
 */
import { css, html, nothing } from 'lit';
import { SiElement, errorState, fmt, icon, loadMeta, skeleton, statCard, t } from '@derspe/site-insights/core.js';
import '@derspe/site-insights/table.js';

class SiteInsightsPage extends SiElement {
  static properties = {
    page: { type: Number },
    language: { type: Number },
    data: { state: true },
    error: { state: true },
  };

  static styles = [
    ...SiElement.styles,
    css`
      :host { display: block; margin: 0 0 16px; font-size: 13px; container-type: inline-size; }
      .grid-tight { grid-template-columns: repeat(4, minmax(0, 1fr)); }
      @container (max-width: 640px) {
        .grid-tight { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      }
      .footer { margin-top: 6px; }
      details { margin-top: 10px; background: var(--si-surface); border: 1px solid var(--si-border); border-radius: var(--si-radius); }
      summary { cursor: pointer; padding: 8px 12px; font-weight: 600; list-style: none; display: flex; align-items: center; gap: 6px; }
      summary::-webkit-details-marker { display: none; }
      summary .chevron { display: inline-flex; transition: transform .15s; }
      details[open] summary .chevron { transform: rotate(180deg); }
      .queries { padding: 0 12px 10px; }
    `,
  ];

  constructor() {
    super();
    this.page = 0;
    this.language = 0;
    this.data = null;
    this.error = null;
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
      return html`<div class="grid-tight">${[0, 1, 2, 3].map(() => html`<div class="stat compact">${skeleton(40)}</div>`)}</div>`;
    }
    const m = d.matomo;
    const g = d.gsc;
    return html`
      <div class="grid-tight">
        ${this.card(m, 'pageviews', t('matomo.pageviews'), 'indigo', 'eye')}
        ${this.card(m, 'entries', t('page.entries'), 'orange', 'targetArrow')}
        ${this.card(g, 'clicks', t('metric.clicks'), 'green', 'mouse')}
        ${this.card(g, 'impressions', t('metric.impressions'), 'blue', 'search')}
      </div>
      <div class="xs dimmed footer">${this.footer(m, g)}</div>
      ${g.error ? nothing : this.queries(g)}`;
  }

  card(source, key, label, color, iconName) {
    if (source.error) {
      const hint = source.status === 503 ? t('error.notConfigured') : t('page.unavailable');
      return statCard({ compact: true, label, value: '–', color: 'gray', icon: iconName, sub: html`<div class="xs dimmed truncate" title=${source.error}>${hint}</div>` });
    }
    return statCard({ compact: true, label, value: fmt.int(source[key]), color, icon: iconName });
  }

  footer(m, g) {
    const parts = [t('widget.last28')];
    if (!m.error) {
      parts.push(`Matomo ${fmt.date(m.range.startDate)} – ${fmt.date(m.range.endDate)}`);
    }
    if (!g.error) {
      parts.push(`Search Console ${fmt.date(g.range.startDate)} – ${fmt.date(g.range.endDate)}`);
    }
    return parts.join(' · ');
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
        <div class="xs dimmed footer">${t('page.anonymizedHint')}</div>
      </div>
    </details>`;
  }
}

customElements.define('derspe-site-insights-page', SiteInsightsPage);
