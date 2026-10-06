import type { EChartsOption } from 'echarts';
import type { ChartSeries, UtilisationRow, WipStage } from '@/types/analytics';
import { withAlpha } from './colour';
import type { ChartPalette } from './useChartTheme';

/**
 * Pure builders: data + palette + direction in, an ECharts option out. Nothing here touches the DOM, so the
 * colour wiring, the RTL flips and the "capacity not set" handling are all testable under Vitest.
 *
 * Every string a user reads is passed in already translated (`ChartLabels`); this file holds none.
 */
export type Dir = 'ltr' | 'rtl';

export type ChartLabels = {
    metres: string;
    date: string;
    allocated: string;
    capacity: string;
    notSet: string;
    /** The one band everything past the eighth is folded into, so the palette is never walked twice. */
    other: string;
};

export type ChartContext = {
    palette: ChartPalette;
    dir: Dir;
    labels: ChartLabels;
    /** dd-mm-yyyy, the format the rest of the app shows dates in. */
    formatDate: (iso: string) => string;
};

/**
 * Digits stay Latin in both locales (the same rule `lib/dates.ts` states): an Urdu reader of this app reads
 * `1,250`, not `۱٬۲۵۰`, because that is what the mill's paperwork and Oracle both use.
 */
const NUMBER_LOCALE = 'en-US';

/**
 * Only fonts the app actually ships or the OS already has. A named Urdu webfont was listed here once but was
 * never bundled, which meant the canvas fell through to the generic stack anyway — an intent nobody could read
 * off the code. If an Urdu face is ever bundled, add it here *and* to the stylesheet in the same change.
 */
const FONT = 'Instrument Sans, ui-sans-serif, system-ui, sans-serif';

function compact(value: number): string {
    return new Intl.NumberFormat(NUMBER_LOCALE, {
        notation: 'compact',
        maximumFractionDigits: 1,
    }).format(value);
}

export function formatMetres(value: number): string {
    return new Intl.NumberFormat(NUMBER_LOCALE, {
        minimumFractionDigits: 0,
        maximumFractionDigits: 2,
    }).format(value);
}

/**
 * Tooltip chrome.
 *
 * ECharts draws the plot on a canvas but the tooltip in a real DOM node, and that node is the one place where
 * `dir` genuinely works — so the RTL fix for tooltips is a CSS direction on the box, not a guess about how the
 * canvas will lay text out.
 */
function tooltip(
    ctx: ChartContext,
    extra: Record<string, unknown> = {},
): Record<string, unknown> {
    return {
        backgroundColor: ctx.palette.surface,
        borderColor: ctx.palette.line,
        borderWidth: 1,
        textStyle: {
            color: ctx.palette.surfaceText,
            fontFamily: FONT,
            fontSize: 12,
        },
        extraCssText:
            ctx.dir === 'rtl'
                ? 'direction: rtl; text-align: right; box-shadow: none;'
                : 'direction: ltr; text-align: left; box-shadow: none;',
        confine: true,
        ...extra,
    };
}

function legend(ctx: ChartContext, names: string[]): Record<string, unknown> {
    return {
        type: 'scroll',
        // Centred on purpose: a centred legend needs no left/right decision, so it reads the same in both
        // directions. The item order is reversed for RTL so the first series is the first one read.
        top: 0,
        left: 'center',
        icon: 'roundRect',
        itemWidth: 10,
        itemHeight: 10,
        itemGap: 12,
        data: ctx.dir === 'rtl' ? [...names].reverse() : names,
        textStyle: {
            color: ctx.palette.mutedText,
            fontFamily: FONT,
            fontSize: 11,
        },
        pageTextStyle: { color: ctx.palette.mutedText },
        pageIconColor: ctx.palette.mutedText,
        pageIconInactiveColor: ctx.palette.line,
    };
}

function axisCommon(ctx: ChartContext): Record<string, unknown> {
    return {
        axisLine: { lineStyle: { color: ctx.palette.line } },
        axisTick: { show: false },
        axisLabel: {
            color: ctx.palette.mutedText,
            fontFamily: FONT,
            fontSize: 11,
        },
        splitLine: { lineStyle: { color: ctx.palette.line, type: 'dashed' } },
        nameTextStyle: {
            color: ctx.palette.mutedText,
            fontFamily: FONT,
            fontSize: 11,
        },
    };
}

function seriesColour(palette: ChartPalette, index: number): string {
    return palette.series[index % palette.series.length];
}

/**
 * The most bands any chart here draws before it folds the rest into one.
 *
 * The palette has ten colours and the mill has twelve departments, so `index % length` would paint the
 * eleventh department the same colour as the first — two bands in one stack that a reader cannot tell apart.
 * Capping below the palette length makes that impossible by construction rather than by counting tokens.
 */
export const MAX_SERIES = 8;

/**
 * Split a list into the `MAX_SERIES` biggest and everything else, biggest first.
 *
 * Callers fold the tail into a single band; the split is here so the department stack and the WIP bars agree
 * on which categories are "the rest".
 */
function splitBySize<T>(
    items: T[],
    size: (item: T) => number,
): { kept: T[]; folded: T[] } {
    const sorted = [...items].sort((a, b) => size(b) - size(a));
    return {
        kept: sorted.slice(0, MAX_SERIES),
        folded: sorted.slice(MAX_SERIES),
    };
}

/**
 * The date-range slider under a long x axis.
 *
 * Shared rather than written per chart: ECharts' stock slider paints its own date labels in light-mode
 * defaults, which on this app's dark surface sits around 2.4:1 — unreadable. Every builder that offers a zoom
 * therefore takes its chrome from here, so a new chart cannot forget.
 */
function zoomSlider(ctx: ChartContext): Record<string, unknown> {
    return {
        type: 'slider',
        height: 18,
        bottom: 6,
        borderColor: ctx.palette.line,
        fillerColor: withAlpha(ctx.palette.primary, 0.15),
        handleStyle: { color: ctx.palette.primary },
        moveHandleStyle: { color: ctx.palette.primary },
        textStyle: {
            color: ctx.palette.mutedText,
            fontFamily: FONT,
            fontSize: 10,
        },
        dataBackground: {
            lineStyle: { color: ctx.palette.line },
            areaStyle: { color: withAlpha(ctx.palette.primary, 0.12) },
        },
        selectedDataBackground: {
            lineStyle: { color: ctx.palette.primary },
            areaStyle: { color: withAlpha(ctx.palette.primary, 0.2) },
        },
    };
}

/** The pair a long range gets: wheel/pinch zoom on the plot, plus the themed slider under it. */
function zooms(ctx: ChartContext, dates: string[]): EChartsOption['dataZoom'] {
    return dates.length > 31
        ? ([
              { type: 'inside', minValueSpan: 7 },
              zoomSlider(ctx),
          ] as EChartsOption['dataZoom'])
        : undefined;
}

/**
 * Production by department, stacked by day.
 *
 * A stack answers the manager's question directly — "how much did the mill make, and who made it" — in one
 * read, where five separate lines would not. Biggest department first, and everything past the eighth is one
 * "other" band: twelve legible bands do not exist, and a thirteenth would repeat a colour.
 */
export function departmentProductionOption(
    dates: string[],
    series: ChartSeries[],
    ctx: ChartContext,
): EChartsOption {
    const { kept, folded } = splitBySize(
        series.filter((s) => s.total !== 0),
        (s) => s.total,
    );
    const drawn: { name: string; data: number[] }[] = [...kept];
    if (folded.length > 0) {
        drawn.push({
            name: ctx.labels.other,
            // Summed per day, so the stack's height is still the mill's total for that day.
            data: dates.map((_, i) =>
                folded.reduce((sum, s) => sum + (s.data[i] ?? 0), 0),
            ),
        });
    }
    const names = drawn.map((s) => s.name);

    return {
        animationDuration: 250,
        color: drawn.map((_, i) => seriesColour(ctx.palette, i)),
        grid: {
            top: 34,
            bottom: dates.length > 31 ? 56 : 28,
            left: 8,
            right: 8,
            containLabel: true,
        },
        legend: legend(ctx, names),
        tooltip: tooltip(ctx, {
            trigger: 'axis',
            axisPointer: {
                type: 'shadow',
                shadowStyle: { color: withAlpha(ctx.palette.primary, 0.08) },
            },
            valueFormatter: (value: unknown) =>
                formatMetres(Number(value ?? 0)),
        }),
        xAxis: {
            type: 'category',
            data: dates.map((d) => ctx.formatDate(d)),
            // Time reads in the page's direction: right-to-left under RTL, like the sentence around it.
            inverse: ctx.dir === 'rtl',
            boundaryGap: true,
            ...axisCommon(ctx),
        },
        yAxis: {
            type: 'value',
            name: ctx.labels.metres,
            nameGap: 12,
            // The value axis sits on the side the reader starts from.
            position: ctx.dir === 'rtl' ? 'right' : 'left',
            nameLocation: 'end',
            axisLabel: {
                color: ctx.palette.mutedText,
                fontFamily: FONT,
                fontSize: 11,
                formatter: (value: number) => compact(value),
            },
            ...axisCommon(ctx),
        },
        dataZoom: zooms(ctx, dates),
        series: drawn.map((s, i) => ({
            type: 'bar' as const,
            name: s.name,
            stack: 'production',
            data: s.data,
            barMaxWidth: 28,
            itemStyle: {
                color: seriesColour(ctx.palette, i),
                borderRadius: [2, 2, 0, 0],
            },
        })),
    };
}

/** Produced / issued / dispatched / loss per day — the mill's pulse, four lines on one grid. */
export function flowOption(
    dates: string[],
    series: ChartSeries[],
    ctx: ChartContext,
): EChartsOption {
    const colours: Record<string, string> = {
        produced: ctx.palette.primary,
        issued: ctx.palette.info,
        dispatched: ctx.palette.success,
        loss: ctx.palette.danger,
    };
    const names = series.map((s) => s.name);

    return {
        animationDuration: 250,
        grid: {
            top: 34,
            bottom: dates.length > 31 ? 56 : 28,
            left: 8,
            right: 8,
            containLabel: true,
        },
        legend: legend(ctx, names),
        tooltip: tooltip(ctx, {
            trigger: 'axis',
            axisPointer: {
                type: 'line',
                lineStyle: { color: ctx.palette.line },
            },
            valueFormatter: (value: unknown) =>
                formatMetres(Number(value ?? 0)),
        }),
        xAxis: {
            type: 'category',
            data: dates.map((d) => ctx.formatDate(d)),
            inverse: ctx.dir === 'rtl',
            boundaryGap: false,
            ...axisCommon(ctx),
        },
        yAxis: {
            type: 'value',
            name: ctx.labels.metres,
            nameGap: 12,
            position: ctx.dir === 'rtl' ? 'right' : 'left',
            nameLocation: 'end',
            axisLabel: {
                color: ctx.palette.mutedText,
                fontFamily: FONT,
                fontSize: 11,
                formatter: (value: number) => compact(value),
            },
            ...axisCommon(ctx),
        },
        dataZoom: zooms(ctx, dates),
        series: series.map((s) => ({
            type: 'line' as const,
            name: s.name,
            data: s.data,
            smooth: false,
            symbol: 'circle',
            symbolSize: 5,
            showSymbol: dates.length <= 45,
            lineStyle: {
                width: 2,
                color: colours[s.key] ?? ctx.palette.primary,
            },
            itemStyle: { color: colours[s.key] ?? ctx.palette.primary },
            areaStyle:
                s.key === 'produced'
                    ? {
                          color: withAlpha(
                              colours[s.key] ?? ctx.palette.primary,
                              0.12,
                          ),
                      }
                    : undefined,
        })),
    };
}

/**
 * Where the open metres are right now — one bar per stage, biggest question first: is work piling up?
 *
 * A mill with twelve departments produces fifteen stages (stock, issued, one per department, finished), more
 * than the palette has colours, so the smallest are folded into one bar. The kept stages hold the server's
 * order — stock → issued → departments → finished is the story — and "other" is appended at the end.
 */
export function wipStageOption(
    stages: WipStage[],
    ctx: ChartContext,
): EChartsOption {
    const { folded } = splitBySize(stages, (s) => s.qty);
    const foldedKeys = new Set(folded.map((s) => s.key));
    const drawn: WipStage[] = stages.filter((s) => !foldedKeys.has(s.key));
    if (folded.length > 0) {
        drawn.push({
            key: 'other',
            name: ctx.labels.other,
            qty: folded.reduce((sum, s) => sum + s.qty, 0),
        });
    }
    const reversed = [...drawn].reverse();

    return {
        animationDuration: 250,
        grid: { top: 12, bottom: 8, left: 8, right: 8, containLabel: true },
        tooltip: tooltip(ctx, {
            trigger: 'item',
            valueFormatter: (value: unknown) =>
                formatMetres(Number(value ?? 0)),
        }),
        xAxis: {
            type: 'value',
            // A horizontal bar grows away from the reader's starting edge, so RTL runs the value axis backwards.
            inverse: ctx.dir === 'rtl',
            axisLabel: {
                color: ctx.palette.mutedText,
                fontFamily: FONT,
                fontSize: 11,
                formatter: (value: number) => compact(value),
            },
            ...axisCommon(ctx),
        },
        yAxis: {
            type: 'category',
            data: reversed.map((s) => s.name),
            position: ctx.dir === 'rtl' ? 'right' : 'left',
            ...axisCommon(ctx),
            splitLine: { show: false },
        },
        series: [
            {
                type: 'bar' as const,
                data: reversed.map((s, i) => ({
                    value: s.qty,
                    itemStyle: {
                        color: seriesColour(ctx.palette, drawn.length - 1 - i),
                    },
                })),
                barMaxWidth: 22,
                itemStyle: { borderRadius: 2 },
            },
        ],
    };
}

/**
 * Allocated against capacity, one row per machine or department.
 *
 * Rows with no capacity on file are dropped from the chart rather than drawn at 0% or 100% — both would be an
 * invented number. They are listed in the table beside it, marked "not set". Over 100% is drawn as it is: the
 * bar simply runs past the capacity ghost, which is the point.
 */
export function utilisationOption(
    rows: UtilisationRow[],
    ctx: ChartContext,
): EChartsOption {
    const drawn = rows.filter(
        (r) => r.capacity_qty !== null && r.capacity_qty > 0,
    );
    const ordered = [...drawn].reverse();

    return {
        animationDuration: 250,
        grid: { top: 30, bottom: 8, left: 8, right: 8, containLabel: true },
        legend: legend(ctx, [ctx.labels.capacity, ctx.labels.allocated]),
        tooltip: tooltip(ctx, {
            trigger: 'axis',
            axisPointer: {
                type: 'shadow',
                shadowStyle: { color: withAlpha(ctx.palette.primary, 0.08) },
            },
            valueFormatter: (value: unknown) =>
                formatMetres(Number(value ?? 0)),
        }),
        xAxis: {
            type: 'value',
            name: ctx.labels.metres,
            nameLocation: 'end',
            nameGap: 10,
            inverse: ctx.dir === 'rtl',
            axisLabel: {
                color: ctx.palette.mutedText,
                fontFamily: FONT,
                fontSize: 11,
                formatter: (value: number) => compact(value),
            },
            ...axisCommon(ctx),
        },
        yAxis: {
            type: 'category',
            data: ordered.map((r) => r.code),
            position: ctx.dir === 'rtl' ? 'right' : 'left',
            ...axisCommon(ctx),
            splitLine: { show: false },
        },
        series: [
            {
                // Drawn first and overlaid, so the allocation sits inside its own capacity like a fuel gauge.
                type: 'bar' as const,
                name: ctx.labels.capacity,
                data: ordered.map((r) => r.capacity_qty ?? 0),
                barMaxWidth: 20,
                itemStyle: { color: ctx.palette.track, borderRadius: 2 },
                silent: true,
            },
            {
                type: 'bar' as const,
                name: ctx.labels.allocated,
                data: ordered.map((r) => ({
                    value: r.allocated_qty,
                    itemStyle: {
                        color: utilisationColour(
                            r.utilisation_pct,
                            ctx.palette,
                        ),
                        borderRadius: 2,
                    },
                })),
                barGap: '-100%',
                barMaxWidth: 12,
            },
        ],
    };
}

/**
 * Traffic light for one utilisation figure. Over 100% is a warning the mill has promised more than it can run;
 * under 60% is spare capacity worth filling; unknown gets the muted track colour and never a verdict.
 */
export function utilisationColour(
    pct: number | null,
    palette: ChartPalette,
): string {
    if (pct === null) {
        return palette.mutedText;
    }
    if (pct > 100) {
        return palette.danger;
    }
    if (pct >= 85) {
        return palette.warning;
    }
    if (pct >= 60) {
        return palette.success;
    }
    return palette.info;
}

/** The same verdict as a token name, for badges and bars outside a chart. */
export function utilisationTone(
    pct: number | null,
): 'unknown' | 'over' | 'high' | 'good' | 'low' {
    if (pct === null) {
        return 'unknown';
    }
    if (pct > 100) {
        return 'over';
    }
    if (pct >= 85) {
        return 'high';
    }
    if (pct >= 60) {
        return 'good';
    }
    return 'low';
}
