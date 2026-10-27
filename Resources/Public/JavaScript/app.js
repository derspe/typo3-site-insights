/**
 * <derspe-site-insights-app>: backend module shell (area navigation, site selection).
 */
import { css, html, nothing } from 'lit';
import { SiElement, errorState, icon, loadMeta, selectInput, skeleton, t } from '@derspe/site-insights/core.js';
import '@derspe/site-insights/gsc.js';
import '@derspe/site-insights/matomo.js';

const STORAGE_KEY = 'derspe-site-insights';

function readState() {
  try {
    return JSON.parse(window.localStorage.getItem(STORAGE_KEY) ?? '{}') ?? {};
  } catch {
    return {};
  }
}

function writeState(state) {
  try {
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
  } catch {
    // storage not available
  }
}

class SiteInsightsApp extends SiElement {
  static properties = {
    meta: { state: true },
    error: { state: true },
    area: { state: true },
    gscTab: { state: true },
    matomoTab: { state: true },
  };

  static styles = [
    ...SiElement.styles,
    css`
      :host { padding: 4px 0 32px; }
      header { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; margin-bottom: 20px; }
      .brand { display: flex; align-items: center; gap: 10px; }
      .brand .logo { display: grid; place-items: center; width: 36px; height: 36px; border-radius: 8px; background: var(--si-primary-light); color: var(--si-primary); }
      nav { display: inline-flex; padding: 4px; gap: 4px; border-radius: var(--si-radius); background: var(--si-surface-alt); border: 1px solid var(--si-border); }
      nav button { display: inline-flex; align-items: center; gap: 8px; height: 32px; padding: 0 14px; border: 0; border-radius: 6px;
        background: transparent; color: var(--si-text); font-size: 14px; font-weight: 500; cursor: pointer; }
      nav button:hover { background: var(--si-hover); }
      nav button.active { background: var(--si-surface); color: var(--si-text-strong); box-shadow: var(--si-shadow); }
      nav button.active .icon { color: var(--si-primary); }
    `,
  ];

  constructor() {
    super();
    const state = readState();
    this.meta = null;
    this.error = null;
    this.area = state.area ?? 'gsc';
    this.gscTab = state.gscTab ?? 'performance';
    this.matomoTab = state.matomoTab ?? 'dashboard';
    this.site = state.site ?? '';
    this.addEventListener('si-open-settings', (e) => {
      this.area = e.detail?.area ?? this.area;
      if (this.area === 'gsc') {
        this.gscTab = 'settings';
      } else {
        this.matomoTab = 'settings';
      }
      this.persist();
    });
    this.addEventListener('si-tab', (e) => {
      if (this.area === 'gsc') {
        this.gscTab = e.detail.tab;
      } else {
        this.matomoTab = e.detail.tab;
      }
      this.persist();
    });
  }

  connectedCallback() {
    super.connectedCallback();
    loadMeta().then((meta) => {
      if (meta.sources?.[this.area] === false) {
        this.area = this.area === 'gsc' ? 'matomo' : 'gsc';
      }
      this.meta = meta;
      if (!meta.sites.some((s) => s.identifier === this.site)) {
        this.site = meta.sites[0]?.identifier ?? '';
      }
    }).catch((e) => { this.error = e; });
  }

  persist() {
    writeState({ area: this.area, site: this.site, gscTab: this.gscTab, matomoTab: this.matomoTab });
  }

  render() {
    if (this.error) {
      return errorState(this.error);
    }
    if (!this.meta) {
      return html`<div class="stack">${skeleton(40, '320px')}${skeleton(300)}</div>`;
    }
    if (this.meta.sites.length === 0) {
      return html`<div class="empty">${t('app.noSites')}</div>`;
    }
    // Sources disabled in the extension configuration are not shown at all
    const areas = [['gsc', 'trendingUp', t('app.area.gsc')], ['matomo', 'chartLine', t('app.area.matomo')]]
      .filter(([key]) => this.meta.sources?.[key] !== false);
    const area = areas.some(([key]) => key === this.area) ? this.area : areas[0][0];
    // A remembered settings tab falls back to the default tab for users who may not configure
    const tab = (current, fallback) => (current === 'settings' && !this.meta.canConfigure ? fallback : current);
    return html`
      <header>
        <div class="brand">
          <span class="logo">${icon('chartBar', 20)}</span>
          <h2 class="title">${t('app.title')}</h2>
        </div>
        ${areas.length > 1 ? html`<nav aria-label=${t('app.title')}>
          ${areas.map(([key, iconName, label]) => html`<button type="button" class=${area === key ? 'active' : ''}
            aria-current=${area === key ? 'page' : nothing}
            @click=${() => { this.area = key; this.persist(); }}>${icon(iconName, 16)}${label}</button>`)}
        </nav>` : nothing}
        ${this.meta.sites.length > 1 ? html`<div class="ml-auto">${selectInput(
          this.meta.sites.map((s) => ({ value: s.identifier, label: s.title })),
          this.site,
          (site) => { this.site = site; this.persist(); },
          { size: '', minWidth: 220, label: t('app.site') },
        )}</div>` : nothing}
      </header>
      ${area === 'gsc'
        ? html`<si-gsc .site=${this.site} .tab=${tab(this.gscTab, 'performance')}></si-gsc>`
        : html`<si-matomo .site=${this.site} .tab=${tab(this.matomoTab, 'dashboard')}></si-matomo>`}
    `;
  }
}

customElements.define('derspe-site-insights-app', SiteInsightsApp);
