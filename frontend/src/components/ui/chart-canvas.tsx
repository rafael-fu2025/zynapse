/**
 * ChartCanvas — React bridge over Chart.js (v4), the vanilla-config style
 * used by the NiceAdmin `charts-chartjs.html` reference.
 *
 * Owns the full chart lifecycle: create on mount, destroy on unmount, and
 * RECREATE when `data`/`options` identity or the theme changes. Recreating
 * (rather than patching) keeps the port literal — configs are declarative
 * snapshots, like the reference page's `new Chart(...)` calls.
 *
 * Theming follows the reference: colors are read from CSS variables at
 * create time (`--primary`, `--chart-1..5`, `--border`, `--muted-foreground`),
 * and a MutationObserver on the root `.dark` class swap rebuilds every chart
 * so light/dark palettes follow the app tokens. Callers must memoize
 * `data`/`options` (useMemo) — identities are the change signal.
 */
import { useEffect, useMemo, useRef, useState } from 'react';
import { Chart } from 'chart.js/auto';
import type { ChartData, ChartOptions, ChartType } from 'chart.js';

/** Resolved value of a CSS custom property on the document root. */
export function cssVar(name: string): string {
  if (typeof window === 'undefined') return '';
  return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
}

/**
 * `color` with the given alpha. Handles any CSS color the stylesheet may
 * hold (hex today, oklch for chart-2..5) by rasterizing it on a 1×1 canvas
 * and reading the pixel back as rgba — no color-format parsing.
 */
export function withAlpha(color: string, alpha: number): string {
  if (color === '') return color;
  const canvas = document.createElement('canvas');
  canvas.width = 1;
  canvas.height = 1;
  const ctx = canvas.getContext('2d');
  if (ctx === null) return color;
  ctx.clearRect(0, 0, 1, 1);
  ctx.fillStyle = color;
  ctx.fillRect(0, 0, 1, 1);
  const [r, g, b] = ctx.getImageData(0, 0, 1, 1).data;
  return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}

/**
 * Increments whenever the root theme flips. Include the value in a useMemo
 * dependency list (or ChartCanvas's rebuild) so token-derived colors are
 * re-resolved after the `.dark` class swap.
 */
export function useThemeKey(): number {
  const [key, setKey] = useState(0);
  useEffect(() => {
    const observer = new MutationObserver(() => setKey((current) => current + 1));
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
    return () => observer.disconnect();
  }, []);
  return key;
}

/** NiceAdmin's global defaults, resolved against the live theme tokens. */
function applyChartDefaults(): void {
  Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
  Chart.defaults.color = cssVar('--muted-foreground');
  Chart.defaults.borderColor = cssVar('--border');
  Chart.defaults.plugins.legend.labels.usePointStyle = true;
  Chart.defaults.plugins.legend.labels.padding = 12;
  Chart.defaults.maintainAspectRatio = false;
}

export function ChartCanvas({
  type,
  data,
  options,
  ariaLabel,
  className,
}: {
  type: ChartType;
  data: ChartData;
  options?: ChartOptions;
  ariaLabel: string;
  className?: string;
}) {
  const canvasRef = useRef<HTMLCanvasElement>(null);
  const themeKey = useThemeKey();

  // Snapshot of the theme tokens driving this create — its identity changes
  // when the theme flips, which recreates the chart with re-resolved colors.
  const theme = useMemo(() => {
    void themeKey;
    return { color: cssVar('--muted-foreground'), border: cssVar('--border') };
  }, [themeKey]);

  useEffect(() => {
    const canvas = canvasRef.current;
    if (canvas === null) return;
    applyChartDefaults();
    const chart = new Chart(canvas, { type, data, options });
    return () => chart.destroy();
  }, [data, options, theme, type]);

  return (
    // overflow-hidden clips the one-frame transient where the canvas still
    // carries its pre-resize inline width while the grid has re-laid out —
    // Chart.js's ResizeObserver lands a frame later. Without it, a rapid
    // viewport resize transiently inflates document.scrollWidth.
    <div className={'relative h-full w-full overflow-hidden ' + (className ?? '')}>
      <canvas ref={canvasRef} role="img" aria-label={ariaLabel} />
    </div>
  );
}
