/**
 * Small stroke icon set (24px grid, 2px stroke) in the style of Tabler Icons.
 */
import { svg, html } from 'lit';

const paths = {
  chartBar: 'M3 13a1 1 0 0 1 1 -1h3a1 1 0 0 1 1 1v6a1 1 0 0 1 -1 1h-3a1 1 0 0 1 -1 -1z M10 5a1 1 0 0 1 1 -1h2a1 1 0 0 1 1 1v14a1 1 0 0 1 -1 1h-2a1 1 0 0 1 -1 -1z M16 9a1 1 0 0 1 1 -1h3a1 1 0 0 1 1 1v10a1 1 0 0 1 -1 1h-3a1 1 0 0 1 -1 -1z',
  chartLine: 'M4 19h16 M4 15l4 -6l4 2l4 -5l4 4',
  search: 'M10 10m-7 0a7 7 0 1 0 14 0a7 7 0 1 0 -14 0 M21 21l-6 -6',
  bookmarks: 'M15 10v11l-5 -3l-5 3v-11a3 3 0 0 1 3 -3h4a3 3 0 0 1 3 3z M11 3h5a3 3 0 0 1 3 3v11',
  clipboardCheck: 'M9 5h-2a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-12a2 2 0 0 0 -2 -2h-2 M9 5a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2a2 2 0 0 1 -2 2h-2a2 2 0 0 1 -2 -2 M9 14l2 2l4 -4',
  settings: 'M12 12m-3 0a3 3 0 1 0 6 0a3 3 0 1 0 -6 0 M12 3v2 M12 19v2 M5.6 5.6l1.4 1.4 M17 17l1.4 1.4 M3 12h2 M19 12h2 M5.6 18.4l1.4 -1.4 M17 7l1.4 -1.4',
  alertCircle: 'M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0 M12 8v4 M12 16h.01',
  infoCircle: 'M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0 M12 9h.01 M11 12h1v4h1',
  mouse: 'M6 7a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v10a4 4 0 0 1 -4 4h-4a4 4 0 0 1 -4 -4z M12 7v4',
  eye: 'M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0 M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6',
  percentage: 'M17 17m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0 M7 7m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0 M6 18l12 -12',
  number: 'M5 9h14 M5 15h14 M11 4l-4 16 M17 4l-4 16',
  sparkles: 'M16 18a2 2 0 0 1 2 2a2 2 0 0 1 2 -2a2 2 0 0 1 -2 -2a2 2 0 0 1 -2 2z M16 6a2 2 0 0 1 2 2a2 2 0 0 1 2 -2a2 2 0 0 1 -2 -2a2 2 0 0 1 -2 2z M9 18a6 6 0 0 1 6 -6a6 6 0 0 1 -6 -6a6 6 0 0 1 -6 6a6 6 0 0 1 6 6z',
  users: 'M9 7m-4 0a4 4 0 1 0 8 0a4 4 0 1 0 -8 0 M3 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2 M16 3.13a4 4 0 0 1 0 7.75 M21 21v-2a4 4 0 0 0 -3 -3.85',
  bounce: 'M9 14l-4 -4l4 -4 M5 10h11a4 4 0 1 1 0 8h-1',
  clock: 'M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0 M12 7v5l3 3',
  target: 'M12 12m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0 M12 12m-5 0a5 5 0 1 0 10 0a5 5 0 1 0 -10 0 M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0',
  targetArrow: 'M12 12m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0 M12 7a5 5 0 1 0 5 5 M13 3.06a9 9 0 1 0 7.92 7.92 M15 6v3h3l3 -3h-3v-3z M15 9l-3 3',
  refresh: 'M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4 M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4',
  scan: 'M4 7v-1a2 2 0 0 1 2 -2h2 M4 17v1a2 2 0 0 0 2 2h2 M16 4h2a2 2 0 0 1 2 2v1 M16 20h2a2 2 0 0 0 2 -2v-1 M5 12h14',
  chevronUp: 'M6 15l6 -6l6 6',
  chevronDown: 'M6 9l6 6l6 -6',
  selector: 'M8 9l4 -4l4 4 M16 15l-4 4l-4 -4',
  trendingUp: 'M3 17l6 -6l4 4l8 -8 M14 7l7 0l0 7',
  google: 'M20.945 11a9 9 0 1 1 -3.284 -5.997l-2.655 2.392a5.5 5.5 0 1 0 2.119 6.605h-4.125v-3z',
  check: 'M5 12l5 5l10 -10',
  copy: 'M7 7m0 2.667a2.667 2.667 0 0 1 2.667 -2.667h8.666a2.667 2.667 0 0 1 2.667 2.667v8.666a2.667 2.667 0 0 1 -2.667 2.667h-8.666a2.667 2.667 0 0 1 -2.667 -2.667z M4.012 16.737a2.005 2.005 0 0 1 -1.012 -1.737v-10c0 -1.1 .9 -2 2 -2h10c.75 0 1.158 .385 1.5 1',
  upload: 'M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2 M7 9l5 -5l5 5 M12 4l0 12',
  x: 'M18 6l-12 12 M6 6l12 12',
  link: 'M9 15l6 -6 M11 6l.463 -.536a5 5 0 0 1 7.071 7.072l-.534 .464 M13 18l-.397 .534a5.068 5.068 0 0 1 -7.127 0a4.972 4.972 0 0 1 0 -7.071l.524 -.463',
  world: 'M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0 M3.6 9h16.8 M3.6 15h16.8 M11.5 3a17 17 0 0 0 0 18 M12.5 3a17 17 0 0 1 0 18',
  pencil: 'M4 20h4l10.5 -10.5a2.828 2.828 0 1 0 -4 -4l-10.5 10.5v4 M13.5 6.5l4 4',
  externalLink: 'M12 6h-6a2 2 0 0 0 -2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-6 M11 13l9 -9 M15 4h5v5',
  database: 'M12 6m-8 0a8 3 0 1 0 16 0a8 3 0 1 0 -16 0 M4 6v6a8 3 0 0 0 16 0v-6 M4 12v6a8 3 0 0 0 16 0v-6',
};

export function icon(name, size = 16, color = 'currentColor') {
  const d = paths[name] ?? paths.infoCircle;
  return html`<svg class="icon" xmlns="http://www.w3.org/2000/svg" width=${size} height=${size} viewBox="0 0 24 24" fill="none"
    stroke=${color} stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${svg`<path d=${d} />`}</svg>`;
}
