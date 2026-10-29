/**
 * <derspe-site-insights-page site="…" page="…" language="…">: key figures of one page in the page module
 * for 7, 28 or 90 days, 3 months or a year, optionally compared with the period before, with a
 * button that opens the page details (visitor paths and search queries).
 */
import { css, html, nothing } from 'lit';
import { SiElement, errorState, fmt, icon, loadMeta, periodControls, readPagePeriod, skeleton, statCard, t, writePagePeriod } from '@derspe/site-insights/core.js';
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
    period: { state: true },
    loading: { state: true },
  };

  static styles = [
    ...SiElement.styles,
    css`
      :host { display: block; margin: 0 0 16px; font-size: 13px; }
      /* Same box as the news figures of a folder */
      .box { background: var(--si-surface); border: 1px solid var(--si-border); border-radius: var(--si-radius); }
      .head { display: flex; align-items: center; gap: 8px; padding: 10px 12px; }
      .body { padding: 0 12px 12px; }
      .grid-tight { grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); }
      .footer { margin-top: 10px; display: flex; align-items: center; gap: 12px 16px; flex-wrap: wrap; }
      .footer .range { flex: 1 1 220px; }
      .reloading { opacity: .55; transition: opacity .15s; }
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
    this.period = readPagePeriod();
    this.loading = false;
  }

  connectedCallback() {
    super.connectedCallback();
    loadMeta().then(() => this.load()).catch((e) => { this.error = e; });
  }

  load() {
    const period = this.period;
    this.loading = true;
    return this.api('page/insights', { params: { page: this.page, language: this.language, period: period.period, compare: period.compare ? '1' : '0' } })
      .then((data) => { if (period === this.period) { this.data = data; this.error = null; } })
      .catch((e) => { if (period === this.period) { this.error = e; } })
      .finally(() => { if (period === this.period) { this.loading = false; } });
  }

  setPeriod(period) {
    this.period = period;
    writePagePeriod(period);
    this.load();
  }

  render() {
    const content = this.content();
    if (content === nothing) {
      return nothing;
    }
    return html`<div class="box">
      <div class="head">${icon('chartBar', 16, 'var(--si-primary)')}<span class="fw6">${t('page.title')}</span></div>
      <div class="body">${content}</div>
    </div>`;
  }

  content() {
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
      <div class=${this.loading ? 'reloading' : ''}>${this.grid(cards)}</div>
      <div class="footer">
        ${detail ? html`<button type="button" class="btn light" @click=${() => { this.detailOpen = true; }}>
          ${icon('route', 16)}${t('pageDetail.open')}</button>` : nothing}
        ${periodControls(this.period, (period) => this.setPeriod(period))}
        <span class="xs dimmed range">${this.footer(m, g)}</span>
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
    // The same text with and without comparison, so it does not wrap differently
    const parts = [];
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
      period=${this.data.period} ?compare=${this.data.compare}
      ?no-gsc=${!gscOk} ?no-matomo=${!matomoOk} @close=${() => { this.detailOpen = false; }}></si-page-detail>`;
  }
}

customElements.define('derspe-site-insights-page', SiteInsightsPage);
