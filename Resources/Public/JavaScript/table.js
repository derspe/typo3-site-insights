/**
 * <si-table>: sortable, striped data table with "show all" paging.
 *
 * columns: [{ key, label, align?: 'right', sortable?: boolean, render?: (row) => template,
 *             sortValue?: (row) => value, maxWidth?: number, title?: string }]
 */
import { css, html, nothing } from 'lit';
import { SiElement, icon, t } from '@derspe/site-insights/core.js';

export class SiTable extends SiElement {
  static properties = {
    columns: { attribute: false },
    rows: { attribute: false },
    sortKey: { type: String, attribute: 'sort-key' },
    sortDir: { type: String, attribute: 'sort-dir' },
    pageSize: { type: Number, attribute: 'page-size' },
    rowKey: { type: String, attribute: 'row-key' },
    selected: { type: String },
    clickable: { type: Boolean },
    serverSort: { type: Boolean, attribute: 'server-sort' },
    showAll: { state: true },
  };

  static styles = [
    ...SiElement.styles,
    css`
      .wrap { overflow-x: auto; }
      table { width: 100%; border-collapse: collapse; font-size: 12px; }
      th, td { padding: 7px 10px; text-align: left; border-bottom: 1px solid var(--si-border); }
      th { font-weight: 600; color: var(--si-text-strong); white-space: nowrap; }
      th.sortable { cursor: pointer; user-select: none; }
      th .inner { display: inline-flex; align-items: center; gap: 2px; }
      th.active { font-weight: 700; }
      th .icon { opacity: .4; }
      th.active .icon { opacity: 1; }
      .right { text-align: right; }
      tbody tr:nth-child(odd) { background: var(--si-surface-alt); }
      tbody tr:hover { background: var(--si-hover); }
      tbody tr:last-child td { border-bottom: 0; }
      tr.clickable { cursor: pointer; }
      tr.selected, tr.selected:hover { background: var(--si-primary-light) !important; }
      td.cell-truncate { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
      .more { margin-top: 10px; }
    `,
  ];

  constructor() {
    super();
    this.columns = [];
    this.rows = null;
    this.sortKey = '';
    this.sortDir = 'desc';
    this.pageSize = 25;
    this.rowKey = '';
    this.selected = '';
    this.clickable = false;
    this.serverSort = false;
    this.showAll = false;
  }

  willUpdate(changed) {
    if (changed.has('rows')) {
      this.showAll = false;
    }
  }

  sortBy(column) {
    if (column.sortable === false) {
      return;
    }
    if (this.serverSort) {
      this.dispatchEvent(new CustomEvent('sort', { detail: { key: column.key } }));
      return;
    }
    if (column.key === this.sortKey) {
      this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
    } else {
      this.sortKey = column.key;
      const first = this.rows?.find((r) => r[column.key] != null)?.[column.key];
      this.sortDir = typeof first === 'string' ? 'asc' : 'desc';
      this.showAll = false;
    }
  }

  sorted() {
    if (!Array.isArray(this.rows) || this.serverSort || !this.sortKey) {
      return this.rows ?? [];
    }
    const column = this.columns.find((c) => c.key === this.sortKey);
    const value = column?.sortValue ?? ((row) => row[this.sortKey]);
    const dir = this.sortDir === 'asc' ? 1 : -1;
    return [...this.rows].sort((a, b) => {
      const av = value(a);
      const bv = value(b);
      if (typeof av === 'string' || typeof bv === 'string') {
        return String(av ?? '').localeCompare(String(bv ?? '')) * dir;
      }
      return ((av ?? -Infinity) - (bv ?? -Infinity) || 0) * dir;
    });
  }

  render() {
    const rows = this.sorted();
    const limited = this.pageSize > 0 && !this.showAll ? rows.slice(0, this.pageSize) : rows;
    const hasMore = this.pageSize > 0 && rows.length > this.pageSize;
    return html`
      <div class="wrap">
        <table>
          <thead>
            <tr>
              ${this.columns.map((c) => {
                const active = c.key === this.sortKey;
                const sortable = c.sortable !== false;
                const sortIcon = active ? (this.sortDir === 'asc' ? 'chevronUp' : 'chevronDown') : 'selector';
                return html`<th class="${c.align === 'right' ? 'right' : ''} ${sortable ? 'sortable' : ''} ${active ? 'active' : ''}"
                  title=${c.title ?? nothing} @click=${() => this.sortBy(c)}>
                  <span class="inner">${c.label}${sortable ? icon(sortIcon, 11) : nothing}</span></th>`;
              })}
            </tr>
          </thead>
          <tbody>
            ${limited.map((row, index) => {
              const key = this.rowKey ? row[this.rowKey] : index;
              return html`<tr class="${this.clickable ? 'clickable' : ''} ${this.selected && key === this.selected ? 'selected' : ''}"
                @click=${() => this.clickable && this.dispatchEvent(new CustomEvent('row-click', { detail: { row, key } }))}>
                ${this.columns.map((c) => html`<td class="${c.align === 'right' ? 'right' : ''} ${c.maxWidth ? 'cell-truncate' : ''}"
                  style=${c.maxWidth ? `max-width:${c.maxWidth}px` : nothing}
                  title=${c.maxWidth ? String(row[c.key] ?? '') : nothing}>${c.render ? c.render(row, index) : (row[c.key] ?? '–')}</td>`)}
              </tr>`;
            })}
          </tbody>
        </table>
      </div>
      ${hasMore ? html`<div class="more"><button type="button" class="btn subtle xs full" @click=${() => { this.showAll = !this.showAll; }}>
        ${this.showAll ? t('table.showLess') : t('table.showAll', { count: rows.length })}</button></div>` : nothing}
    `;
  }
}

customElements.define('si-table', SiTable);
