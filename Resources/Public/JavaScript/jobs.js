/**
 * Drives a background job (index scan, backfill) from the browser: every step request
 * processes a time-boxed chunk on the server and returns the progress.
 */
import { api, t } from '@derspe/site-insights/core.js';

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

export class JobRunner {
  /**
   * @param {string} site
   * @param {(job: object) => void} onUpdate
   */
  constructor(site, onUpdate) {
    this.site = site;
    this.onUpdate = onUpdate;
    this.stopped = false;
    this.running = false;
  }

  stop() {
    this.stopped = true;
  }

  async start(type, options = {}) {
    const { job } = await api('job/start', { site: this.site, method: 'POST', body: { type, ...options } });
    return this.follow(job);
  }

  async resume(type) {
    const { job } = await api('job/active', { site: this.site, params: { type } });
    if (job) {
      return this.follow(job);
    }
    return null;
  }

  async follow(job) {
    if (this.running) {
      return job;
    }
    this.running = true;
    this.onUpdate(job);
    try {
      while (!this.stopped && job && (job.status === 'pending' || job.status === 'running')) {
        const result = await api('job/step', { site: this.site, method: 'POST', body: { id: job.id } });
        job = result.job;
        this.onUpdate(job);
        await sleep(300);
      }
    } finally {
      this.running = false;
    }
    return job;
  }
}

export function jobProgressText(job) {
  if (!job) {
    return '';
  }
  if (job.status === 'error' && job.error) {
    return job.progress?.key && job.progress.key !== 'job.pending'
      ? `${t(job.progress.key, job.progress.args ?? {})} ${job.error}`
      : job.error;
  }
  return job.progress?.key ? t(job.progress.key, job.progress.args ?? {}) : '';
}
