import { onBeforeUnmount, readonly, ref, type Ref } from 'vue';
import { readCssColour } from './colour';

/**
 * The palette a chart paints with, read from the app's own CSS custom properties.
 *
 * Why this exists: ECharts keeps the option it was last given and repaints from that cache, so a colour baked
 * into an option at build time would survive the light/dark toggle unchanged. The fix is to make the palette a
 * reactive value and derive every option from it — when the palette changes the option object changes, and
 * vue-echarts pushes it through `setOption`.
 *
 * The toggle itself is a class on `<html>` (`useAppearance` adds/removes `.dark`), and "system" appearance
 * follows `prefers-color-scheme` without touching that class. Both are watched: a MutationObserver for the
 * class, a media query listener for the system case.
 */
export type ChartPalette = {
    /** Categorical series colours, in the order a chart should use them. */
    series: string[];
    primary: string;
    success: string;
    warning: string;
    danger: string;
    info: string;
    /** Axis labels and other text drawn on the chart. */
    text: string;
    mutedText: string;
    /** Split lines, axis lines, the ghost bar behind an allocation. */
    line: string;
    track: string;
    /** Tooltip box. */
    surface: string;
    surfaceText: string;
};

/** Fallbacks are the light palette's sRGB values; they are only used before the stylesheet is readable. */
const FALLBACK: ChartPalette = {
    series: ['#44916d', '#3b6fd4', '#8a6a1f', '#8a5bd0', '#1f7f96'],
    primary: '#3f8765',
    success: '#2f8a3f',
    warning: '#8a6a1f',
    danger: '#c23b32',
    info: '#3b6fd4',
    text: '#22302b',
    mutedText: '#6b7a74',
    line: '#dde5e1',
    track: '#eef2f0',
    surface: '#ffffff',
    surfaceText: '#22302b',
};

function read(): ChartPalette {
    return {
        series: [
            readCssColour('--chart-1', FALLBACK.series[0]),
            readCssColour('--chart-2', FALLBACK.series[1]),
            readCssColour('--chart-3', FALLBACK.series[2]),
            readCssColour('--chart-4', FALLBACK.series[3]),
            readCssColour('--chart-5', FALLBACK.series[4]),
        ],
        primary: readCssColour('--primary', FALLBACK.primary),
        success: readCssColour('--success', FALLBACK.success),
        warning: readCssColour('--warning', FALLBACK.warning),
        danger: readCssColour('--destructive', FALLBACK.danger),
        info: readCssColour('--info', FALLBACK.info),
        text: readCssColour('--foreground', FALLBACK.text),
        mutedText: readCssColour('--muted-foreground', FALLBACK.mutedText),
        line: readCssColour('--border', FALLBACK.line),
        track: readCssColour('--muted', FALLBACK.track),
        surface: readCssColour('--popover', FALLBACK.surface),
        surfaceText: readCssColour(
            '--popover-foreground',
            FALLBACK.surfaceText,
        ),
    };
}

/** One shared palette for the whole page: every chart on a screen repaints from the same read. */
const palette = ref<ChartPalette>(FALLBACK);
let watchers = 0;
let observer: MutationObserver | null = null;
let media: MediaQueryList | null = null;

function refresh(): void {
    palette.value = read();
}

function start(): void {
    if (typeof window === 'undefined' || watchers > 0) {
        watchers += 1;
        return;
    }
    watchers += 1;
    refresh();
    observer = new MutationObserver(refresh);
    observer.observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['class'],
    });
    // Guarded: the "system" appearance resolves through this, but jsdom (and any SSR pass) has no matchMedia,
    // and a missing media listener must not stop the class observer above from working.
    media =
        typeof window.matchMedia === 'function'
            ? window.matchMedia('(prefers-color-scheme: dark)')
            : null;
    media?.addEventListener('change', refresh);
}

function stop(): void {
    watchers = Math.max(0, watchers - 1);
    if (watchers > 0) {
        return;
    }
    observer?.disconnect();
    observer = null;
    media?.removeEventListener('change', refresh);
    media = null;
}

export function useChartTheme(): {
    palette: Readonly<Ref<ChartPalette>>;
    refresh: () => void;
} {
    start();
    onBeforeUnmount(stop);

    return {
        palette: readonly(palette) as Readonly<Ref<ChartPalette>>,
        refresh,
    };
}

/** Test seam: resets the module-level palette and detaches the watchers. */
export function __resetChartTheme(): void {
    observer?.disconnect();
    observer = null;
    media?.removeEventListener('change', refresh);
    media = null;
    watchers = 0;
    palette.value = FALLBACK;
}

export { FALLBACK as FALLBACK_PALETTE };
