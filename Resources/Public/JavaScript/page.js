/**
 * <derspe-site-insights-page site="…" page="…" language="…">: key figures of one page in the page module,
 * with a button that opens the page details (visitor paths and search queries).
 */
import { css, html, nothing } from 'lit';
import { SiElement, errorState, fmt, icon, loadMeta, skeleton, statCard, t } from '@derspe/site-insights/core.js';
import '@derspe/site-insights/page-detail.js';

class SiteInsightsPage extends SiElement {
  static properties = {
    page: { type: Number },
    language: { type: Number },
    matomo: { type: Boolean },
    gsc: { type: Boolean },
    data: { state: true },
    error: { state: true },
    detailOpen: { state: true },
  };

  static styles = [
    ...SiElement.styles,
    css`
      :host { display: block; margin: 0 0 16px; font-size: 13px; }
      .grid-tight { grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); }
      .footer { margin-top: 10px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
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
    this.detailOpen = false;
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
        this.card(m, 'matomo', 'pageviews', t('matomo.pageviews'), 'indigo', 'eye'),
        this.card(m, 'matomo', 'entries', t('page.entries'), 'orange', 'targetArrow'),
      );
    }
    if (g.configured !== false) {
      cards.push(
        this.card(g, 'gsc', 'clicks', t('metric.clicks'), 'green', 'mouse'),
        this.card(g, 'gsc', 'impressions', t('metric.impressions'), 'blue', 'search'),
        this.positionCard(g),
      );
    }
    if (cards.length === 0) {
      return nothing;
    }
    const detail = (m.configured !== false && !m.error) || (g.configured !== false && !g.error);
    return html`
      ${this.grid(cards)}
      <div class="footer">
        ${detail ? html`<button type="button" class="btn light" @click=${() => { this.detailOpen = true; }}>
          ${icon('route', 16)}${t('pageDetail.open')}</button>` : nothing}
        <span class="xs dimmed">${this.footer(m, g)}</span>
      </div>
      ${detail && this.detailOpen ? this.detail(m, g) : nothing}`;
  }

  grid(cards) {
    return html`<div class="grid-tight">${cards}</div>`;
  }

  unavailable(source, name, label, iconName) {
    return statCard({ compact: true, label, value: '–', color: 'gray', icon: iconName, source: name,
      sub: html`<div class="xs dimmed truncate" title=${source.error}>${t('page.unavailable')}</div>` });
  }

  card(source, name, key, label, color, iconName) {
    if (source.error) {
      return this.unavailable(source, name, label, iconName);
    }
    return statCard({ compact: true, label, value: fmt.int(source[key]), raw: source[key], compare: source.compare?.[key], color, icon: iconName, source: name });
  }

  positionCard(g) {
    const label = t('metric.avgPosition');
    if (g.error) {
      return this.unavailable(g, 'gsc', label, 'number');
    }
    // Without impressions there is no position
    const position = g.impressions > 0 ? g.position : null;
    const compare = g.compare?.impressions > 0 ? g.compare.position : null;
    return statCard({ compact: true, label, value: fmt.dec(position), raw: position, compare, invert: true, color: 'teal', icon: 'number', source: 'gsc' });
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
   * Page details of the page in the periods of the key figures; a source with an error is left out.
   */
  detail(m, g) {
    const gscOk = g.configured !== false && !g.error;
    const matomoOk = m.configured !== false && !m.error;
    const gscRange = gscOk ? g.range : m.range;
    const matomoRange = matomoOk ? m.range : g.range;
    return html`<si-page-detail .site=${this.site} url=${this.data.url}
      start=${gscRange.startDate} end=${gscRange.endDate} matomo-start=${matomoRange.startDate} matomo-end=${matomoRange.endDate}
      ?no-gsc=${!gscOk} ?no-matomo=${!matomoOk} @close=${() => { this.detailOpen = false; }}></si-page-detail>`;
  }
}

customElements.define('derspe-site-insights-page', SiteInsightsPage);
