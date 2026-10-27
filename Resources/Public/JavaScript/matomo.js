/**
 * Matomo views: dashboard and settings.
 */
import { html, nothing } from 'lit';
import {
  SiElement, alertBox, checkbox, deltaText, errorState, fmt, getMeta, icon, segmented, selectInput,
  showsNewsSetting, skeleton, spinnerButton, statCard, t, PALETTE,
} from '@derspe/site-insights/core.js';
import '@derspe/site-insights/table.js';
import '@derspe/site-insights/chart.js';

export const MATOMO_DEFAULT_RANGE = { period: 'range', date: 'last30', segment: '', compare: '' };

const PRESETS = [
  { key: 'today', period: 'day', date: 'today' },
  { key: 'yesterday', period: 'day', date: 'yesterday' },
  { key: '7d', period: 'range', date: 'last7' },
  { key: '30d', period: 'range', date: 'last30' },
  { key: '90d', period: 'range', date: 'last90' },
  { key: '1y', period: 'range', date: 'last365' },
];

// Same limit as MatomoDashboardService::CUSTOM_MAX_MONTHS
const CUSTOM_MAX_MONTHS = 12;

function isoDay(d) {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function customBounds() {
  const today = new Date();
  const min = new Date(today);
  min.setMonth(min.getMonth() - CUSTOM_MAX_MONTHS);
  return { min: isoDay(min), max: isoDay(today) };
}

function compareLabel(compare) {
  return compare === 'year' ? t('compare.previousYear') : t('compare.previousPeriod');
}

export function matomoKpiCards(kpis, kpisCompare, compact = false) {
  if (!kpis) {
    return html`<div class="grid-kpis cols-4">${[0, 1, 2, 3].map(() => html`<div class="stat">${skeleton(48)}</div>`)}</div>`;
  }
  const c = kpisCompare ?? {};
  const pct = (v) => (v == null ? null : parseFloat(v));
  return html`<div class="grid-kpis cols-4">
    ${statCard({ compact, label: t('matomo.visits'), value: fmt.int(kpis.visits), raw: kpis.visits, compare: c.visits, color: 'blue', icon: 'users' })}
    ${statCard({ compact, label: t('matomo.pageviews'), value: fmt.int(kpis.pageviews), raw: kpis.pageviews, compare: c.pageviews, color: 'indigo', icon: 'eye' })}
    ${statCard({ compact, label: t('matomo.bounceRate'), value: kpis.bounceRate, raw: pct(kpis.bounceRate), compare: pct(c.bounceRate), invert: true, color: 'orange', icon: 'bounce' })}
    ${statCard({ compact, label: t('matomo.avgTime'), value: fmt.duration(kpis.avgTimeOnSite), raw: kpis.avgTimeOnSite, compare: c.avgTimeOnSite, color: 'teal', icon: 'clock' })}
  </div>`;
}

export function visitorChart(data, height = 200) {
  const rows = data?.visitorSeries;
  const series = [{ key: 'value', label: t('matomo.visits'), color: 'blue' }];
  if (data?.compare) {
    series.push({ key: 'compare', label: `${t('matomo.visits')} ${compareLabel(data.compare)}`, color: 'blue', shade: 3 });
  }
  if (!rows) {
    return skeleton(height);
  }
  if (rows.length === 0) {
    return html`<div class="empty">${t('empty.noData')}</div>`;
  }
  return html`<si-chart type="area" height=${height} .data=${rows} .series=${series} ?legend=${!!data.compare} ?dots=${rows.length <= 30}></si-chart>`;
}

export class SiMatomo extends SiElement {
  static properties = {
    tab: { type: String },
    range: { state: true },
    customFrom: { state: true },
    customTo: { state: true },
    segments: { state: true },
    data: { state: true },
    error: { state: true },
  };

  constructor() {
    super();
    this.tab = 'dashboard';
    this.range = { ...MATOMO_DEFAULT_RANGE };
    this.customFrom = '';
    this.customTo = '';
    this.segments = [];
    this.data = null;
    this.error = null;
    this.requestId = 0;
  }

  willUpdate(changed) {
    if (changed.has('site') && this.site) {
      this.segments = [];
      this.api('matomo/segments').then((s) => { this.segments = Array.isArray(s) ? s : []; }).catch(() => {});
    }
    if ((changed.has('site') || changed.has('range') || (changed.has('tab') && this.tab === 'dashboard')) && this.site && this.tab === 'dashboard') {
      this.load();
    }
  }

  async load() {
    const id = ++this.requestId;
    this.data = null;
    this.error = null;
    try {
      const data = await this.api('matomo/dashboard', { params: { ...this.range } });
      if (id === this.requestId) {
        this.data = data;
      }
    } catch (error) {
      if (id === this.requestId) {
        this.error = error;
      }
    }
  }

  setTab(tab) {
    this.tab = tab;
    this.dispatchEvent(new CustomEvent('si-tab', { detail: { tab }, bubbles: true, composed: true }));
  }

  dateRangeLabel() {
    const r = this.data?.dateRange;
    if (!r) {
      return '';
    }
    return r.start === r.end ? fmt.date(r.start) : `${fmt.shortDate(r.start)} – ${fmt.date(r.end)}`;
  }

  render() {
    const tabs = [['dashboard', 'chartBar', t('matomo.tab.dashboard')], ['settings', 'settings', t('tab.settings')]]
      .filter(([key]) => key !== 'settings' || getMeta().canConfigure);
    return html`
      <div class="tabs" role="tablist">
        ${tabs.map(([key, iconName, label]) => html`<button type="button" role="tab" class="tab ${this.tab === key ? 'active' : ''}"
          aria-selected=${this.tab === key ? 'true' : 'false'} @click=${() => this.setTab(key)}>${icon(iconName, 14)}${label}</button>`)}
      </div>
      ${this.tab === 'dashboard' ? this.renderDashboard() : html`<si-matomo-settings .site=${this.site}></si-matomo-settings>`}
    `;
  }

  renderPicker() {
    const v = this.range;
    const change = (next) => { this.range = next; };
    return html`<div class="group mb">
      ${segmented(PRESETS.map((p) => ({ label: t(`preset.${p.key}`), value: p, active: v.period === p.period && v.date === p.date })),
        (item) => {
          this.customFrom = '';
          this.customTo = '';
          change({ ...v, period: item.value.period, date: item.value.date });
        })}
      ${this.renderCustomRange(v, change)}
      ${this.segments.length ? selectInput(
        [{ value: '', label: t('matomo.allVisitors') }, ...this.segments.map((s) => ({ value: s.definition, label: s.name }))],
        v.segment, (segment) => change({ ...v, segment }), { minWidth: 180, label: t('matomo.segment') },
      ) : nothing}
      ${checkbox(t('compare.enable'), !!v.compare, (checked) => change({ ...v, compare: checked ? 'previous' : '' }))}
      ${v.compare ? selectInput([
        { value: 'previous', label: t('compare.previousPeriod') },
        { value: 'year', label: t('compare.previousYear') },
      ], v.compare, (compare) => change({ ...v, compare }), { minWidth: 160 }) : nothing}
    </div>`;
  }

  /**
   * Custom range, applied as soon as both dates are set and inside the allowed window.
   */
  renderCustomRange(v, change) {
    const { min, max } = customBounds();
    const apply = (from, to) => {
      this.customFrom = from;
      this.customTo = to;
      if (from && to && from >= min && to <= max && from <= to) {
        change({ ...v, period: 'range', date: `${from},${to}` });
      }
    };
    return html`<div class="group-xs nowrap" title=${t('matomo.range.limit', { months: CUSTOM_MAX_MONTHS })}>
      <input type="date" class="input xs" style="width:auto" aria-label=${t('matomo.range.from')}
        .value=${this.customFrom} min=${min} max=${this.customTo || max} @change=${(e) => apply(e.target.value, this.customTo)}>
      <span class="dimmed">–</span>
      <input type="date" class="input xs" style="width:auto" aria-label=${t('matomo.range.to')}
        .value=${this.customTo} min=${this.customFrom || min} max=${max} @change=${(e) => apply(this.customFrom, e.target.value)}>
    </div>`;
  }

  renderDashboard() {
    const d = this.data;
    const loading = !d && !this.error;
    const cmp = d?.compare ? compareLabel(d.compare) : '';
    return html`
      ${this.renderPicker()}
      ${this.error ? html`<div class="mb">${errorState(this.error, () => this.setTab('settings'))}</div>` : nothing}
      <div class="mb">${matomoKpiCards(d?.kpis, d?.kpisCompare)}</div>
      ${this.renderGoalKpis(d, loading)}
      <div class="grid cols-2 mb">
        <div class="paper">
          <h4 class="card-title tight">${t('matomo.visitorChart')}</h4>
          <div class="xs dimmed mb-sm">${this.dateRangeLabel()}</div>
          ${this.error ? html`<div class="empty">${t('empty.noData')}</div>` : visitorChart(d)}
        </div>
        ${this.renderGoalChart(d, loading, cmp)}
      </div>
      <div class="grid cols-2">
        <div class="paper">
          <h4 class="card-title">${t('matomo.topPages')}</h4>
          ${!d ? skeleton(160) : html`<si-table sort-key="pageviews" .rows=${d.topPages} .columns=${[
            { key: 'url', label: t('matomo.url'), maxWidth: 280 },
            { key: 'pageviews', label: t('matomo.pageviews'), align: 'right', render: (r) => fmt.int(r.pageviews) },
            ...(d.topPages.some((p) => p.pageviewsCompare != null) ? [
              { key: 'pageviewsCompare', label: cmp, align: 'right', render: (r) => html`<span class="dimmed">${fmt.int(r.pageviewsCompare)}</span>` },
              { key: 'pageviewsDelta', label: 'Δ', align: 'right', sortValue: (r) => (r.pageviewsDelta ? parseFloat(r.pageviewsDelta) : null), render: (r) => deltaText(r.pageviewsDelta, r.pageviewsDeltaPos) },
            ] : []),
          ]}></si-table>`}
        </div>
        <div class="paper">
          <h4 class="card-title">${t('matomo.sources')}</h4>
          ${!d ? skeleton(160) : html`<si-table page-size="0" .rows=${d.trafficSources} .columns=${[
            { key: 'label', label: t('matomo.source'), sortable: false },
            { key: 'visits', label: t('matomo.visits'), align: 'right', sortable: false, render: (r) => fmt.int(r.visits) },
            ...(d.trafficSources.some((s) => s.visitsDelta) ? [{ key: 'visitsDelta', label: 'Δ', align: 'right', sortable: false, render: (r) => deltaText(r.visitsDelta, r.visitsDeltaPos) }] : []),
            { key: 'percent', label: t('matomo.share'), align: 'right', sortable: false },
          ]}></si-table>`}
        </div>
      </div>`;
  }

  renderGoalKpis(d, loading) {
    if (loading) {
      return html`<div class="grid-goals mb">${[0, 1, 2].map(() => html`<div class="stat">${skeleton(48)}</div>`)}</div>`;
    }
    const goals = d?.goalKpis ?? [];
    if (goals.length === 0) {
      return nothing;
    }
    const total = goals.reduce((s, g) => s + (g.conversions ?? 0), 0);
    const hasCompare = goals.some((g) => g.conversionsCompare != null);
    const totalCompare = hasCompare ? goals.reduce((s, g) => s + (g.conversionsCompare ?? 0), 0) : null;
    return html`<div class="grid-goals mb">
      ${statCard({ label: t('matomo.goalsTotal'), value: fmt.int(total), raw: total, compare: totalCompare, color: 'green', icon: 'targetArrow', highlight: true })}
      ${goals.map((g) => statCard({ label: g.name, value: fmt.int(g.conversions), raw: g.conversions, compare: g.conversionsCompare, color: 'green', icon: 'target' }))}
    </div>`;
  }

  renderGoalChart(d, loading, cmp) {
    const goals = d?.goals ?? [];
    const series = [];
    goals.forEach((g, i) => {
      const color = PALETTE[i % PALETTE.length];
      series.push({ key: g.name, label: g.name, color });
      if (d.compare) {
        series.push({ key: `${g.name}|compare`, label: `${g.name} ${cmp}`, color, shade: 3 });
      }
    });
    const rows = d?.goalSeries ?? [];
    let body;
    if (loading) {
      body = skeleton(200);
    } else if (goals.length === 0) {
      body = html`<div class="empty xs">${t('matomo.noGoals')}</div>`;
    } else if (rows.length === 0) {
      body = html`<div class="empty">${t('empty.noData')}</div>`;
    } else {
      body = html`<si-chart type=${rows.length === 1 ? 'bar' : 'area'} height="200" .data=${rows} .series=${series}
        ?legend=${series.length > 1} ?dots=${rows.length <= 30}></si-chart>`;
    }
    return html`<div class="paper">
      <h4 class="card-title tight">${t('matomo.goalChart')}</h4>
      <div class="xs dimmed mb-sm">${this.dateRangeLabel()}</div>
      ${body}
    </div>`;
  }
}

class SiMatomoSettings extends SiElement {
  static properties = {
    form: { state: true }, tokenSet: { state: true }, goals: { state: true },
    saving: { state: true }, saved: { state: true }, error: { state: true },
  };

  constructor() {
    super();
    this.form = null;
    this.tokenSet = false;
    this.goals = [];
    this.saving = false;
    this.saved = false;
    this.error = null;
  }

  willUpdate(changed) {
    if (changed.has('site') && this.site && getMeta().canConfigure) {
      this.load();
    }
  }

  async load() {
    try {
      const v = await this.api('settings');
      this.tokenSet = !!v.matomo_token_set;
      this.form = {
        matomo_url: v.matomo_url ?? '',
        matomo_token: '',
        matomo_site_id: v.matomo_site_id ?? '',
        matomo_goal_ids: (v.matomo_goal_ids ?? []).map(String),
        ...(showsNewsSetting('matomo') ? { news_detail_pid: v.news_detail_pid || '' } : {}),
      };
    } catch (e) {
      this.error = e;
    }
    this.loadGoals();
  }

  loadGoals() {
    this.api('matomo/goals').then((g) => { this.goals = Array.isArray(g) ? g : []; }).catch(() => { this.goals = []; });
  }

  async save() {
    this.saving = true;
    this.error = null;
    try {
      await this.api('settings', { method: 'POST', body: { values: this.form } });
      await this.load();
      this.saved = true;
      setTimeout(() => { this.saved = false; }, 2000);
    } catch (e) {
      this.error = e;
    } finally {
      this.saving = false;
    }
  }

  toggleGoal(id, checked) {
    const ids = new Set(this.form.matomo_goal_ids);
    if (checked) {
      ids.add(id);
    } else {
      ids.delete(id);
    }
    this.form = { ...this.form, matomo_goal_ids: [...ids] };
  }

  render() {
    if (!getMeta().canConfigure) {
      return html`<div class="paper" style="max-width:860px">${alertBox(t('settings.maintainerOnly'), { color: 'yellow' })}</div>`;
    }
    if (!this.form) {
      return this.error ? errorState(this.error) : skeleton(300);
    }
    const isAdmin = true;
    const input = (key, label, { type = 'text', placeholder = '', description = '' } = {}) => html`<div class="field">
      <label for="m-${key}">${label}</label>
      ${description ? html`<span class="description">${description}</span>` : nothing}
      <input id="m-${key}" class="input" type=${type} .value=${String(this.form[key] ?? '')} placeholder=${placeholder} autocomplete="off"
        ?disabled=${!isAdmin} @input=${(e) => { this.form = { ...this.form, [key]: e.target.value }; }}>
    </div>`;
    return html`<div class="paper" style="max-width:860px">
      <h3 class="title mb">${t('matomo.settings.title')}</h3>
      <div class="stack-sm">
        ${this.error ? alertBox(this.error.message) : nothing}
        ${input('matomo_url', t('matomo.settings.url'), { placeholder: 'https://matomo.example.com', description: t('matomo.settings.url.description') })}
        ${input('matomo_token', t('matomo.settings.token'), {
          type: 'password',
          description: t('matomo.settings.token.description'),
          placeholder: this.tokenSet ? t('settings.secretSet') : t('matomo.settings.token.placeholder'),
        })}
        ${input('matomo_site_id', t('matomo.settings.siteId'), { placeholder: '1' })}
        <div class="field">
          <label>${t('matomo.settings.goals')}</label>
          <span class="description">${t('matomo.settings.goals.description')}</span>
          <div class="stack-xs" style="margin-top:6px">
            ${this.goals.length === 0 ? html`<span class="xs dimmed">${t('matomo.settings.goals.empty')}</span>`
              : this.goals.map((g) => html`<label class="checkbox sm"><input type="checkbox" ?disabled=${!isAdmin}
                .checked=${this.form.matomo_goal_ids.includes(g.id)} @change=${(e) => this.toggleGoal(g.id, e.target.checked)}>${g.name} (#${g.id})</label>`)}
          </div>
        </div>
        ${showsNewsSetting('matomo') ? input('news_detail_pid', t('settings.newsDetailPid'), { type: 'number', description: t('settings.newsDetailPid.description') }) : nothing}
        ${isAdmin ? html`<div>${spinnerButton(this.saved ? t('settings.saved') : t('settings.save'), {
          loading: this.saving, color: this.saved ? 'success' : '', onClick: () => this.save(),
        })}</div>` : nothing}
      </div>
    </div>`;
  }
}

customElements.define('si-matomo', SiMatomo);
customElements.define('si-matomo-settings', SiMatomoSettings);
