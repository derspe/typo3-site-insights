/**
 * Base of the parts of the page details that load their data themselves from "endpoint" with
 * "params"; a change of either reloads.
 */
import { html } from 'lit';
import { SiElement, errorState } from '@derspe/site-insights/core.js';

/**
 * While other params load, the data shown so far stays (dimmed), so the modal keeps its height;
 * only another URL starts empty.
 */
export class SiLoading extends SiElement {
  static properties = {
    endpoint: { type: String },
    params: { attribute: false },
    data: { state: true },
    error: { state: true },
    reloading: { type: Boolean, reflect: true },
  };

  constructor() {
    super();
    this.endpoint = '';
    this.params = {};
    this.data = null;
    this.error = null;
    this.reloading = false;
    this.requestKey = '';
    this.dataUrl = null;
  }

  willUpdate(changed) {
    if (!(changed.has('site') || changed.has('endpoint') || changed.has('params')) || !this.site || !this.endpoint) {
      return;
    }
    const key = JSON.stringify([this.site, this.endpoint, this.params]);
    if (key === this.requestKey) {
      return;
    }
    this.requestKey = key;
    if (this.params?.url !== this.dataUrl) {
      this.data = null;
    }
    this.reloading = this.data !== null;
    this.error = null;
    this.api(this.endpoint, { params: this.params })
      .then((data) => { if (key === this.requestKey) { this.data = data; this.dataUrl = this.params?.url; } })
      .catch((error) => { if (key === this.requestKey) { this.data = null; this.error = error; } })
      .finally(() => { if (key === this.requestKey) { this.reloading = false; } });
  }
}

/**
 * A source that is not set up is no error here, only a note.
 */
export function softError(error) {
  return error.status === 503 ? html`<div class="dimmed">${error.message}</div>` : errorState(error);
}
