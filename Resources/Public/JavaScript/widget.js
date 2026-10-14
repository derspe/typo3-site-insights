/**
 * <derspe-site-insights-widget view="…" site="…">: content of the dashboard widgets.
 */
import { css, html, nothing } from 'lit';
import { SiElement, errorState, fmt, loadMeta, skeleton, statCard, t } from '@derspe/site-insights/core.js';
import { matomoKpiCards, visitorChart } from '@derspe/site-insights/matomo.js';
import '@derspe/site-insights/gsc.js';
import '@derspe/site-insights/chart.js';
import '@derspe/site-insights/table.js';

class SiteInsightsWidget extends SiElement {
  static properties = {
    view: { type: String },
    data: { state: true },
    error: { state: true },
    ready: { state: true },
  };

  static styles = [
    ...SiElement.styles,
    css`
      :host { height: 100%; font-size: 13px; padding: 12px 16px; container-type: inline-size; }
      .footer { margin-top: 8px; }
      .grid-kpis.cols-4, .grid-tight { grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; }
      .stat, .stat.compact { padding: 8px 12px; min-height: 0; }
      .stat .value, .stat.compact .value { font-size: 18px; }
      @container (max-width: 520px) {
        .grid-kpis.cols-4, .grid-tight { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      }
    `,
  ];

  constructor() {
    super();
    this.view = '';
    this.data = null;
    this.error = null;
    this.ready = false;
  }

  connectedCallback() {
    super.connectedCallback();
    loadMeta().then((meta) => {
      if (!this.site || !meta.sites.some((s) => s.identifier === this.site)) {
        this.site = meta.sites[0]?.identifier ?? '';
      }
      this.ready = true;
      this.load();
    }).catch((e) => { this.error = e; });
  }

  async load() {
    const requests = {
      'gsc-kpis': ['gsc/dashboard', { period: 'range', date: 'last28', searchType: 'web', compare: 'previous' }],
      'gsc-chart': ['gsc/dashboard', { period: 'range', date: 'last28', searchType: 'web', compare: '' }],
      'gsc-queries': ['gsc/dashboard', { period: 'range', date: 'last28', searchType: 'web', compare: 'previous' }],
      'matomo-kpis': ['matomo/dashboard', { period: 'range', date: 'last30', compare: 'previous' }],
      'matomo-visitors': ['matomo/dashboard', { period: 'range', date: 'last30', compare: '' }],
    };
    const request = requests[this.view];
    if (!request) {
      return;
    }
    try {
      this.data = await this.api(request[0], { params: request[1] });
    } catch (e) {
      this.error = e;
    }
  }

  render() {
    if (this.error) {
      return errorState(this.error);
    }
    if (!this.ready) {
      return skeleton(120);
    }
    switch (this.view) {
      case 'gsc-kpis': return this.gscKpis();
      case 'gsc-chart': return this.gscChart();
      case 'gsc-queries': return this.gscQueries();
      case 'gsc-visibility': return html`<si-gsc-visibility compact .site=${this.site} .range=${{ period: 'range', date: 'last90' }}></si-gsc-visibility>`;
      case 'matomo-kpis': return html`${matomoKpiCards(this.data?.kpis, this.data?.kpisCompare, true)}
        <div class="xs dimmed footer">${t('widget.last30')} · ${t('widget.vsPrevious')}</div>`;
      case 'matomo-visitors': return html`${visitorChart(this.data, 270)}<div class="xs dimmed footer">${t('widget.last30')}</div>`;
      default: return nothing;
    }
  }

  gscKpis() {
    const d = this.data;
    if (!d) {
      return html`<div class="grid-tight">${[0, 1, 2, 3].map(() => html`<div class="stat">${skeleton(40)}</div>`)}</div>`;
    }
    const k = d.kpis;
    const c = d.kpisCompare ?? {};
    return html`<div class="grid-tight">
        ${statCard({ compact: true, label: t('metric.clicks'), value: fmt.int(k.clicks), raw: k.clicks, compare: c.clicks, color: 'green', icon: 'mouse' })}
        ${statCard({ compact: true, label: t('metric.impressions'), value: fmt.int(k.impressions), raw: k.impressions, compare: c.impressions, color: 'blue', icon: 'eye' })}
        ${statCard({ compact: true, label: t('metric.ctr'), value: fmt.pct(k.ctr), raw: k.ctr, compare: c.ctr, color: 'orange', icon: 'percentage' })}
        ${statCard({ compact: true, label: t('metric.avgPosition'), value: fmt.dec(k.position), raw: k.position, compare: c.position, invert: true, color: 'teal', icon: 'number' })}
      </div>
      <div class="xs dimmed footer">${t('widget.last28')} · ${t('gsc.dataDate', { date: fmt.date(d.dataDate) })}</div>`;
  }

  gscChart() {
    const d = this.data;
    if (!d) {
      return skeleton(270);
    }
    return html`<si-chart type="line" height="270" legend .data=${d.series} .series=${[
      { key: 'clicks', label: t('metric.clicks'), color: 'green' },
      { key: 'impressions', label: t('metric.impressions'), color: 'blue', axis: 'right' },
    ]}></si-chart><div class="xs dimmed footer">${t('widget.last28')}</div>`;
  }

  gscQueries() {
    const d = this.data;
    if (!d) {
      return skeleton(240);
    }
    return html`<si-table page-size="10" sort-key="clicks" .rows=${d.topQueries} .columns=${[
      { key: 'query', label: t('metric.query'), maxWidth: 220 },
      { key: 'clicks', label: t('metric.clicks'), align: 'right', render: (r) => fmt.int(r.clicks) },
      { key: 'position', label: t('metric.positionShort'), align: 'right', render: (r) => fmt.dec(r.position) },
      { key: 'clicksDelta', label: 'Δ', align: 'right', sortValue: (r) => (r.clicksDelta ? parseFloat(r.clicksDelta) : null),
        render: (r) => (r.clicksDelta ? html`<span class=${r.clicksDeltaPos ? 'c-green' : 'c-red'}>${r.clicksDelta}</span>` : html`<span class="dimmed">–</span>`) },
    ]}></si-table><div class="xs dimmed footer">${t('widget.last28')} · ${t('widget.vsPrevious')}</div>`;
  }
}

customElements.define('derspe-site-insights-widget', SiteInsightsWidget);
