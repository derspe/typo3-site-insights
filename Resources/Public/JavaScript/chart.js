/**
 * <si-chart>: line, area and bar charts on top of the Chart.js bundle of EXT:dashboard,
 * styled like Mantine charts (thin lines, dashed grid, legend on top).
 *
 * series: [{ key, label, color: 'blue', shade?: 3|6, axis?: 'right' }]
 * data:   [{ <xKey>: 'x label', <key>: number, ... }]
 */
import { css, html } from 'lit';
import { Chart, registerables } from 'chart.js';
import { COLORS, SiElement, fmt } from '@derspe/site-insights/core.js';

Chart.register(...registerables);

function rgba(hex, alpha) {
  const n = parseInt(hex.slice(1), 16);
  return `rgba(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}, ${alpha})`;
}

export class SiChart extends SiElement {
  static properties = {
    type: { type: String },
    data: { attribute: false },
    series: { attribute: false },
    xKey: { type: String, attribute: 'x-key' },
    height: { type: Number },
    legend: { type: Boolean },
    reversed: { type: Boolean },
    dots: { type: Boolean },
    curve: { type: String },
    yLabel: { type: String, attribute: 'y-label' },
    rightLabel: { type: String, attribute: 'right-label' },
    xFormat: { type: String, attribute: 'x-format' },
  };

  static styles = [
    ...SiElement.styles,
    css`
      .canvas-wrap { position: relative; width: 100%; }
      canvas { display: block; }
      .probe-text { color: var(--si-dimmed); }
      .probe-grid { color: var(--si-border); }
      .probe-surface { color: var(--si-surface); }
      .probe-strong { color: var(--si-text-strong); }
    `,
  ];

  constructor() {
    super();
    this.type = 'line';
    this.data = [];
    this.series = [];
    this.xKey = 'date';
    this.height = 200;
    this.legend = false;
    this.reversed = false;
    this.dots = false;
    this.curve = 'linear';
    this.yLabel = '';
    this.rightLabel = '';
    this.xFormat = 'date';
    this.chart = null;
    this.schemeQuery = window.matchMedia('(prefers-color-scheme: dark)');
    this.onSchemeChange = () => this.rebuild();
  }

  connectedCallback() {
    super.connectedCallback();
    this.schemeQuery.addEventListener('change', this.onSchemeChange);
  }

  disconnectedCallback() {
    super.disconnectedCallback();
    this.schemeQuery.removeEventListener('change', this.onSchemeChange);
    this.chart?.destroy();
    this.chart = null;
  }

  render() {
    return html`
      <span class="probe-text" hidden></span><span class="probe-grid" hidden></span>
      <span class="probe-surface" hidden></span><span class="probe-strong" hidden></span>
      <div class="canvas-wrap" style="height:${this.height}px"><canvas role="img"></canvas></div>`;
  }

  updated() {
    this.rebuild();
  }

  probe(name) {
    const el = this.renderRoot.querySelector(`.probe-${name}`);
    return el ? getComputedStyle(el).color : '#868e96';
  }

  formatX(value) {
    if (this.xFormat === 'date' && typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)) {
      return fmt.shortDate(value);
    }
    return value;
  }

  rebuild() {
    const canvas = this.renderRoot.querySelector('canvas');
    if (!canvas) {
      return;
    }
    const textColor = this.probe('text');
    const gridColor = this.probe('grid');
    const surface = this.probe('surface');
    const strong = this.probe('strong');
    const rows = Array.isArray(this.data) ? this.data : [];
    const labels = rows.map((r) => this.formatX(r[this.xKey]));
    const isBar = this.type === 'bar';
    const isArea = this.type === 'area';
    const hasRight = this.series.some((s) => s.axis === 'right');
    const showDots = this.dots || rows.length <= 1;

    const datasets = this.series.map((s) => {
      const [light, strongColor] = COLORS[s.color] ?? COLORS.blue;
      const color = s.shade === 3 ? light : strongColor;
      return {
        label: s.label ?? s.key,
        data: rows.map((r) => r[s.key] ?? null),
        yAxisID: s.axis === 'right' ? 'right' : 'y',
        borderColor: color,
        backgroundColor: isBar ? color : (isArea ? rgba(color, 0.18) : color),
        fill: isArea ? 'origin' : false,
        borderWidth: 2,
        tension: this.curve === 'monotone' ? 0.35 : 0,
        cubicInterpolationMode: this.curve === 'monotone' ? 'monotone' : 'default',
        pointRadius: showDots ? 3 : 0,
        pointHoverRadius: 4,
        pointBackgroundColor: color,
        pointBorderColor: surface,
        borderRadius: isBar ? 4 : 0,
        maxBarThickness: s.maxBarThickness ?? 60,
        spanGaps: true,
      };
    });

    const axis = (position, title, display = true) => ({
      display,
      position,
      reverse: this.reversed && position === 'left',
      beginAtZero: !this.reversed,
      grid: { color: gridColor, drawTicks: false, tickBorderDash: [5, 5], drawOnChartArea: position === 'left' },
      border: { display: false, dash: [5, 5] },
      ticks: { color: textColor, font: { size: 11 }, padding: 6, maxTicksLimit: 6, callback: (v) => fmt.int(v) },
      title: title ? { display: true, text: title, color: textColor, font: { size: 11 } } : { display: false },
    });

    const config = {
      type: isBar ? 'bar' : 'line',
      data: { labels, datasets },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: false,
        interaction: { mode: 'index', intersect: false },
        layout: { padding: { top: 4, right: 4 } },
        plugins: {
          legend: {
            display: this.legend,
            position: 'top',
            align: 'end',
            labels: { color: textColor, boxWidth: 10, boxHeight: 10, usePointStyle: true, pointStyle: 'circle', font: { size: 12 } },
          },
          tooltip: {
            backgroundColor: surface,
            titleColor: strong,
            bodyColor: textColor,
            borderColor: gridColor,
            borderWidth: 1,
            padding: 10,
            cornerRadius: 8,
            boxPadding: 4,
            usePointStyle: true,
            callbacks: {
              label: (ctx) => ` ${ctx.dataset.label}: ${ctx.parsed.y == null ? '–' : fmt.dec(ctx.parsed.y, Number.isInteger(ctx.parsed.y) ? 0 : 2)}`,
            },
          },
        },
        scales: {
          x: {
            grid: { display: false },
            border: { color: gridColor },
            ticks: { color: textColor, font: { size: 11 }, maxRotation: 0, autoSkip: true, autoSkipPadding: 16 },
          },
          y: axis('left', this.yLabel),
          ...(hasRight ? { right: axis('right', this.rightLabel) } : {}),
        },
      },
    };

    if (this.chart) {
      this.chart.destroy();
    }
    this.chart = new Chart(canvas.getContext('2d'), config);
  }
}

customElements.define('si-chart', SiChart);
