/**
 * Shared runtime of the Site Insights UI: API access, labels, number formatting,
 * design tokens and small template helpers.
 */
import { LitElement, css, html, nothing, unsafeCSS } from 'lit';
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import { icon } from '@derspe/site-insights/icons.js';

export { icon };

// ---------------------------------------------------------------------------
// API
// ---------------------------------------------------------------------------

export class ApiError extends Error {
  constructor(message, status) {
    super(message);
    this.status = status;
  }
}

function apiUrl() {
  const url = globalThis.TYPO3?.settings?.ajaxUrls?.derspe_site_insights_api;
  if (!url) {
    throw new ApiError('AJAX route not available', 0);
  }
  return url;
}

function cleanParams(params) {
  return Object.fromEntries(Object.entries(params).filter(([, v]) => v !== undefined && v !== null && v !== ''));
}

/**
 * @param {string} endpoint e.g. "gsc/dashboard"
 * @param {{site?: string, params?: object, method?: 'GET'|'POST', body?: object}} options
 */
export async function api(endpoint, { site = '', params = {}, method = 'GET', body = {} } = {}) {
  let request = new AjaxRequest(apiUrl()).withQueryArguments(cleanParams({ endpoint, site, ...params }));
  try {
    const response = method === 'GET'
      ? await request.get({ cache: 'no-cache' })
      : await request.post({ endpoint, site, ...body }, { headers: { 'Content-Type': 'application/json' } });
    return await response.resolve();
  } catch (error) {
    const response = error?.response;
    if (!response) {
      throw new ApiError(error?.message ?? 'Network error', 0);
    }
    let message = `HTTP ${response.status}`;
    try {
      const data = await response.clone().json();
      message = data?.error ?? message;
    } catch {
      // not JSON
    }
    throw new ApiError(message, response.status);
  }
}

let metaPromise = null;
let meta = { sites: [], isAdmin: false, canConfigure: false, sources: { matomo: true, gsc: true }, locale: 'en', labels: {} };

export function loadMeta() {
  metaPromise ??= api('meta').then((data) => {
    meta = data;
    return data;
  });
  return metaPromise;
}

export function getMeta() {
  return meta;
}

// ---------------------------------------------------------------------------
// Labels
// ---------------------------------------------------------------------------

/**
 * Translates a label key; "{name}" placeholders are replaced by args.name.
 */
export function t(key, args = {}) {
  let label = meta.labels?.[key] ?? key;
  for (const [name, value] of Object.entries(args)) {
    label = label.replaceAll(`{${name}}`, String(value));
  }
  return label;
}

// ---------------------------------------------------------------------------
// Formatting
// ---------------------------------------------------------------------------

function locale() {
  const l = meta.locale && meta.locale !== 'default' ? meta.locale : (document.documentElement.lang || 'en');
  return l.replace('_', '-');
}

export const fmt = {
  int(value) {
    return value == null ? '–' : Number(value).toLocaleString(locale(), { maximumFractionDigits: 0 });
  },
  dec(value, digits = 1) {
    return value == null ? '–' : Number(value).toLocaleString(locale(), { minimumFractionDigits: digits, maximumFractionDigits: digits });
  },
  pct(ratio, digits = 2) {
    return ratio == null ? '–' : `${fmt.dec(ratio * 100, digits)}%`;
  },
  date(iso) {
    if (!iso) {
      return '–';
    }
    const d = new Date(iso.length === 10 ? `${iso}T00:00:00` : iso);
    return Number.isNaN(d.getTime()) ? iso : d.toLocaleDateString(locale(), { day: '2-digit', month: '2-digit', year: 'numeric' });
  },
  shortDate(iso) {
    if (!iso || iso.length !== 10) {
      return iso ?? '';
    }
    const d = new Date(`${iso}T00:00:00`);
    return d.toLocaleDateString(locale(), { day: '2-digit', month: '2-digit' });
  },
  duration(seconds) {
    if (seconds == null) {
      return '–';
    }
    const m = Math.floor(seconds / 60);
    const s = Math.round(seconds % 60);
    return `${m}:${String(s).padStart(2, '0')}`;
  },
  timeAgo(unixSeconds) {
    if (!unixSeconds) {
      return '–';
    }
    const days = Math.floor((Date.now() / 1000 - unixSeconds) / 86400);
    if (days <= 0) {
      return t('time.today');
    }
    if (days === 1) {
      return t('time.yesterday');
    }
    return t('time.daysAgo', { days });
  },
  path(url) {
    try {
      return new URL(url).pathname;
    } catch {
      return url;
    }
  },
};

// ---------------------------------------------------------------------------
// Design tokens (follow the TYPO3 backend color scheme via light-dark())
// ---------------------------------------------------------------------------

export const COLORS = {
  blue: ['#74c0fc', '#228be6'],
  green: ['#8ce99a', '#40c057'],
  orange: ['#ffc078', '#fd7e14'],
  teal: ['#63e6be', '#12b886'],
  indigo: ['#91a7ff', '#4c6ef5'],
  grape: ['#e599f7', '#be4bdb'],
  violet: ['#b197fc', '#7950f2'],
  cyan: ['#66d9e8', '#15aabf'],
  red: ['#ffa8a8', '#fa5252'],
  yellow: ['#ffe066', '#fab005'],
  pink: ['#faa2c1', '#e64980'],
  gray: ['#ced4da', '#868e96'],
};

export const PALETTE = ['green', 'blue', 'orange', 'grape', 'cyan', 'red', 'teal', 'pink'];

const colorVars = Object.entries(COLORS)
  .map(([name, [light, strong]]) => `--si-${name}-3: ${light}; --si-${name}-6: ${strong};`)
  .join('\n');

export const baseStyles = [
  unsafeCSS(`:host { ${colorVars} }`),
  css`
    :host {
      --si-font: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji';
      --si-text: light-dark(#212529, #c9c9c9);
      --si-text-strong: light-dark(#000, #fff);
      --si-dimmed: light-dark(#868e96, #828282);
      --si-faint: light-dark(#adb5bd, #5c5c5c);
      --si-surface: light-dark(#ffffff, #2e2e2e);
      --si-surface-alt: light-dark(#f8f9fa, #262626);
      --si-hover: light-dark(#f1f3f5, #383838);
      --si-border: light-dark(#dee2e6, #424242);
      --si-input-bg: light-dark(#ffffff, #2e2e2e);
      --si-primary: #228be6;
      --si-primary-hover: #1c7ed6;
      --si-primary-light: light-dark(rgba(34, 139, 230, .1), rgba(34, 139, 230, .15));
      --si-primary-light-color: light-dark(#228be6, #4dabf7);
      --si-radius: 8px;
      --si-radius-sm: 4px;
      --si-shadow: 0 1px 3px rgba(0, 0, 0, .05), 0 1px 2px rgba(0, 0, 0, .1);
      display: block;
      font-family: var(--si-font);
      font-size: 14px;
      line-height: 1.55;
      color: var(--si-text);
      -webkit-font-smoothing: antialiased;
    }
    *, *::before, *::after { box-sizing: border-box; }
    [hidden] { display: none !important; }
    .icon { flex-shrink: 0; vertical-align: middle; }

    /* Layout */
    .stack { display: flex; flex-direction: column; gap: 16px; }
    .stack-sm { display: flex; flex-direction: column; gap: 10px; }
    .stack-xs { display: flex; flex-direction: column; gap: 4px; }
    .group { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .group-xs { display: flex; align-items: center; gap: 6px; }
    .nowrap { flex-wrap: nowrap; }
    .between { justify-content: space-between; }
    .end { align-items: flex-end; }
    .grow { flex: 1 1 auto; min-width: 0; }
    .ml-auto { margin-left: auto; }
    .grid { display: grid; gap: 16px; grid-template-columns: minmax(0, 1fr); }
    .grid-tight { display: grid; gap: 10px; grid-template-columns: repeat(2, minmax(0, 1fr)); }
    @media (min-width: 900px) {
      .grid.cols-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    .grid-kpis { display: grid; gap: 16px; grid-template-columns: repeat(2, minmax(0, 1fr)); }
    @media (min-width: 700px) {
      .grid-kpis.cols-4 { grid-template-columns: repeat(4, minmax(0, 1fr)); }
    }
    .grid-goals { display: grid; gap: 16px; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); }
    .mb { margin-bottom: 16px; }
    .mt { margin-top: 16px; }
    .mb-sm { margin-bottom: 10px; }

    /* Typography */
    h2.title { font-size: 26px; line-height: 1.35; font-weight: 700; margin: 0; color: var(--si-text-strong); }
    h3.title { font-size: 18px; line-height: 1.4; font-weight: 700; margin: 0; color: var(--si-text-strong); }
    .card-title { font-size: 14px; font-weight: 600; margin: 0 0 10px; color: var(--si-text-strong); }
    .card-title.tight { margin-bottom: 2px; }
    .xs { font-size: 12px; }
    .sm { font-size: 14px; }
    .lg { font-size: 18px; }
    .xl { font-size: 20px; }
    .fw6 { font-weight: 600; }
    .fw7 { font-weight: 700; }
    .dimmed { color: var(--si-dimmed); }
    .upper { text-transform: uppercase; letter-spacing: .02em; }
    .truncate { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .center { text-align: center; }
    .empty { color: var(--si-dimmed); font-size: 14px; text-align: center; padding: 32px 0; }
    .c-green { color: light-dark(#2f9e44, #51cf66); }
    .c-red { color: light-dark(#e03131, #ff6b6b); }
    code, .code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12px; padding: 1px 5px; border-radius: var(--si-radius-sm); background: var(--si-surface-alt); }

    /* Paper / Card */
    .paper { background: var(--si-surface); border: 1px solid var(--si-border); border-radius: var(--si-radius); padding: 16px; min-width: 0; }
    .paper.sm { padding: 10px 12px; }
    .paper.alt { background: var(--si-surface-alt); }
    .stat { background: var(--si-surface); border: 1px solid var(--si-border); border-radius: var(--si-radius); padding: 12px 16px; min-width: 0; }
    .stat.compact { padding: 10px 12px; min-height: 88px; }
    .stat .label { font-size: 12px; font-weight: 600; text-transform: uppercase; color: var(--si-dimmed); letter-spacing: .02em; }
    .stat .value { font-size: 20px; font-weight: 700; line-height: 1.3; }
    .stat.compact .value { font-size: 18px; }
    .stat.highlight { background: light-dark(rgba(64, 192, 87, .1), rgba(64, 192, 87, .15)); }
    .section-head { display: flex; align-items: center; gap: 6px; margin-bottom: 10px; font-size: 12px; font-weight: 600; text-transform: uppercase; color: var(--si-dimmed); }

    /* Buttons */
    button { font: inherit; }
    .btn {
      display: inline-flex; align-items: center; justify-content: center; gap: 6px;
      height: 36px; padding: 0 18px; border-radius: var(--si-radius-sm);
      border: 1px solid transparent; background: var(--si-primary); color: #fff;
      font-size: 14px; font-weight: 600; cursor: pointer; white-space: nowrap; transition: background .1s;
    }
    .btn:hover { background: var(--si-primary-hover); }
    .btn:disabled { cursor: not-allowed; opacity: .55; }
    .btn.xs { height: 30px; padding: 0 14px; font-size: 12px; }
    .btn.default { background: var(--si-input-bg); color: var(--si-text); border-color: var(--si-border); }
    .btn.default:hover { background: var(--si-hover); }
    .btn.subtle { background: transparent; color: var(--si-primary-light-color); }
    .btn.subtle:hover { background: var(--si-primary-light); }
    .btn.light { background: var(--si-primary-light); color: var(--si-primary-light-color); }
    .btn.danger { background: light-dark(rgba(250, 82, 82, .1), rgba(250, 82, 82, .15)); color: light-dark(#e03131, #ff8787); }
    .btn.success { background: #40c057; }
    .btn.full { width: 100%; }
    .btn .spinner { width: 14px; height: 14px; border: 2px solid currentColor; border-right-color: transparent; border-radius: 50%; animation: si-spin .7s linear infinite; }
    @keyframes si-spin { to { transform: rotate(360deg); } }

    .segmented { display: inline-flex; }
    .segmented .btn { border-radius: 0; margin-left: -1px; }
    .segmented .btn:first-child { border-radius: var(--si-radius-sm) 0 0 var(--si-radius-sm); margin-left: 0; }
    .segmented .btn:last-child { border-radius: 0 var(--si-radius-sm) var(--si-radius-sm) 0; }
    .segmented .btn.default { font-weight: 600; }
    .segmented .btn:not(.default) { position: relative; z-index: 1; border-color: var(--si-primary); }

    /* Inputs */
    .field { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
    .field > label { font-size: 14px; font-weight: 500; color: var(--si-text-strong); }
    .field .description { font-size: 12px; color: var(--si-dimmed); margin-top: -2px; }
    input.input, select.input, textarea.input {
      font: inherit; font-size: 14px; color: var(--si-text);
      height: 36px; padding: 0 12px; border-radius: var(--si-radius-sm);
      border: 1px solid var(--si-border); background: var(--si-input-bg); width: 100%; min-width: 0;
    }
    textarea.input { height: auto; min-height: 72px; padding: 8px 12px; resize: vertical; }
    select.input { padding-right: 30px; appearance: none; cursor: pointer;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23868e96' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M8 9l4 -4l4 4 M16 15l-4 4l-4 -4'/%3E%3C/svg%3E");
      background-repeat: no-repeat; background-position: right 8px center; }
    .input.xs { height: 30px; font-size: 12px; padding: 0 10px; }
    select.input.xs { padding-right: 28px; }
    .input:focus { outline: none; border-color: var(--si-primary); }
    .input:disabled { opacity: .6; cursor: not-allowed; }
    .checkbox { display: inline-flex; align-items: center; gap: 8px; cursor: pointer; font-size: 14px; user-select: none; }
    .checkbox.xs { font-size: 12px; gap: 6px; }
    .checkbox input { width: 18px; height: 18px; accent-color: var(--si-primary); margin: 0; cursor: pointer; }
    .checkbox.xs input { width: 16px; height: 16px; }

    /* Badge */
    .badge {
      display: inline-flex; align-items: center; height: 20px; padding: 0 8px; border-radius: 1000px;
      font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .25px; white-space: nowrap;
      background: var(--badge-color, var(--si-blue-6)); color: #fff;
    }
    .badge.light { background: color-mix(in srgb, var(--badge-color, var(--si-blue-6)) 15%, transparent); color: var(--badge-color, var(--si-blue-6)); }
    .badge.lg { height: 26px; font-size: 13px; padding: 0 12px; }

    /* Alert */
    .alert { display: flex; gap: 12px; padding: 12px 16px; border-radius: var(--si-radius); font-size: 14px;
      background: color-mix(in srgb, var(--alert-color, var(--si-blue-6)) 12%, transparent);
      color: var(--si-text); }
    .alert .alert-icon { color: var(--alert-color, var(--si-blue-6)); padding-top: 2px; }
    .alert .alert-title { font-weight: 700; color: var(--alert-color, var(--si-blue-6)); }
    .alert.red { --alert-color: var(--si-red-6); }
    .alert.yellow { --alert-color: light-dark(#f08c00, var(--si-yellow-6)); }
    .alert.green { --alert-color: var(--si-green-6); }

    /* Skeleton */
    .skeleton { border-radius: var(--si-radius-sm); background: var(--si-hover); animation: si-pulse 1.5s ease-in-out infinite; }
    @keyframes si-pulse { 50% { opacity: .5; } }

    /* Tabs */
    .tabs { display: flex; gap: 0; border-bottom: 2px solid var(--si-border); margin-bottom: 16px; flex-wrap: wrap; }
    .tab {
      display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px; margin-bottom: -2px;
      background: none; border: 0; border-bottom: 2px solid transparent; color: var(--si-text);
      font-size: 14px; cursor: pointer; border-radius: var(--si-radius-sm) var(--si-radius-sm) 0 0;
    }
    .tab:hover { background: var(--si-hover); border-bottom-color: var(--si-border); }
    .tab.active { border-bottom-color: var(--si-primary); color: var(--si-text-strong); font-weight: 500; }
    .tab .icon { color: var(--si-dimmed); }
    .tab.active .icon { color: var(--si-primary); }

    /* Progress */
    .progress { height: 8px; border-radius: 8px; background: var(--si-hover); overflow: hidden; }
    .progress > div { height: 100%; background: var(--si-primary); transition: width .3s; background-image: linear-gradient(45deg, rgba(255,255,255,.15) 25%, transparent 25%, transparent 50%, rgba(255,255,255,.15) 50%, rgba(255,255,255,.15) 75%, transparent 75%, transparent); background-size: 16px 16px; animation: si-stripes 1s linear infinite; }
    @keyframes si-stripes { from { background-position: 16px 0; } to { background-position: 0 0; } }

    ul.list { margin: 0; padding-left: 20px; font-size: 14px; }
    ul.list li { margin: 2px 0; }
  `,
];

/**
 * Base class: shared styles + "site" property.
 */
export class SiElement extends LitElement {
  static properties = {
    site: { type: String },
  };

  static styles = baseStyles;

  constructor() {
    super();
    this.site = '';
  }

  api(endpoint, options = {}) {
    return api(endpoint, { site: this.site, ...options });
  }
}

// ---------------------------------------------------------------------------
// Template helpers
// ---------------------------------------------------------------------------

export function skeleton(height, width = '100%') {
  return html`<div class="skeleton" style="height:${height}px;width:${width}"></div>`;
}

/**
 * Percentage change between two values; invert = lower is better.
 */
export function delta(current, compare, invert = false) {
  if (current == null || compare == null || compare === 0 || Number.isNaN(current) || Number.isNaN(compare)) {
    return nothing;
  }
  const pct = ((current - compare) / compare) * 100;
  const positive = invert ? pct <= 0 : pct >= 0;
  return html`<span class="xs fw6 ${positive ? 'c-green' : 'c-red'}">${pct >= 0 ? '+' : ''}${fmt.dec(pct, 1)}%</span>`;
}

export function deltaText(text, positive) {
  if (!text) {
    return html`<span class="dimmed">–</span>`;
  }
  return html`<span class=${positive ? 'c-green' : 'c-red'}>${text}</span>`;
}

/**
 * @param {{label: string, value: unknown, icon?: string, color?: string, raw?: number, compare?: number, invert?: boolean, compact?: boolean, sub?: unknown, highlight?: boolean}} o
 */
export function statCard(o) {
  const color = o.color ?? 'blue';
  return html`
    <div class="stat ${o.compact ? 'compact' : ''} ${o.highlight ? 'highlight' : ''}">
      <div class="group-xs nowrap between" style="margin-bottom:${o.compact ? 2 : 4}px">
        <span class="label truncate" title=${o.title ?? o.label}>${o.label}</span>
        ${o.icon ? icon(o.icon, o.compact ? 14 : 16, `var(--si-${color}-6)`) : nothing}
      </div>
      <div class="group-xs nowrap" style="align-items:baseline">
        <span class="value" style="color:var(--si-${color}-6)">${o.value ?? '–'}</span>
        ${o.compact ? delta(o.raw, o.compare, o.invert) : nothing}
      </div>
      ${o.compact ? nothing : delta(o.raw, o.compare, o.invert)}
      ${o.sub ?? nothing}
    </div>`;
}

export function alertBox(message, { color = 'red', title = '', action = nothing } = {}) {
  return html`
    <div class="alert ${color}" role="alert">
      <span class="alert-icon">${icon(color === 'green' ? 'check' : 'alertCircle', 18)}</span>
      <div class="stack-xs grow">
        ${title ? html`<span class="alert-title">${title}</span>` : nothing}
        <span>${message}</span>
        ${action}
      </div>
    </div>`;
}

export function badge(text, color = 'blue', variant = '') {
  return html`<span class="badge ${variant}" style="--badge-color:var(--si-${color}-6)">${text}</span>`;
}

export function statusColor(status) {
  if (status === 'PASS') {
    return 'green';
  }
  if (status === 'FAIL') {
    return 'red';
  }
  if (status === 'PARTIAL') {
    return 'yellow';
  }
  return 'gray';
}

/**
 * Segmented button group.
 * @param {{label: string, value: string, active: boolean}[]} items
 */
export function segmented(items, onSelect) {
  return html`<div class="segmented" role="group">
    ${items.map((item) => html`<button type="button" class="btn xs ${item.active ? '' : 'default'}" aria-pressed=${item.active ? 'true' : 'false'}
      @click=${() => onSelect(item)}>${item.label}</button>`)}
  </div>`;
}

export function selectInput(options, value, onChange, { size = 'xs', minWidth = 140, label = '' } = {}) {
  return html`<select class="input ${size}" style="min-width:${minWidth}px;width:auto" aria-label=${label || nothing}
    @change=${(e) => onChange(e.target.value)}>
    ${options.map((o) => html`<option value=${o.value} ?selected=${o.value === value}>${o.label}</option>`)}
  </select>`;
}

export function checkbox(label, checked, onChange, size = 'xs') {
  return html`<label class="checkbox ${size}"><input type="checkbox" .checked=${checked}
    @change=${(e) => onChange(e.target.checked)}>${label}</label>`;
}

export function spinnerButton(label, { loading = false, disabled = false, onClick, variant = '', size = '', iconName = '', color = '' } = {}) {
  return html`<button type="button" class="btn ${variant} ${size} ${color}" ?disabled=${disabled || loading} @click=${onClick}>
    ${loading ? html`<span class="spinner"></span>` : (iconName ? icon(iconName, 14) : nothing)}${label}</button>`;
}

/**
 * Shows a configuration hint for "not configured / not connected" errors (HTTP 503).
 * Only system maintainers get the button to the settings.
 */
export function errorState(error, onOpenSettings) {
  if (!error) {
    return nothing;
  }
  if (error.status === 503 && onOpenSettings) {
    return alertBox(error.message, {
      color: 'yellow',
      title: t('error.notConfigured'),
      action: meta.canConfigure
        ? html`<div><button type="button" class="btn xs light" @click=${onOpenSettings}>${icon('settings', 14)}${t('action.openSettings')}</button></div>`
        : nothing,
    });
  }
  return alertBox(error.message ?? String(error));
}
