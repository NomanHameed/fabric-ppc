import { describe, expect, it } from 'vitest';
import type { ChartContext } from '@/components/charts/options';
import {
    MAX_SERIES,
    departmentProductionOption,
    flowOption,
    utilisationColour,
    utilisationOption,
    utilisationTone,
    wipStageOption,
} from '@/components/charts/options';
import type { ChartPalette } from '@/components/charts/useChartTheme';
import type { ChartSeries, UtilisationRow } from '@/types/analytics';

const palette: ChartPalette = {
    // Ten markers, matching the ten `--chart-*` tokens: a fixture that was one colour short of the real
    // palette could never catch the ring being walked twice.
    series: [
        '#111111',
        '#222222',
        '#333333',
        '#444444',
        '#555555',
        '#666666',
        '#777777',
        '#888888',
        '#999999',
        '#aaaaaa',
    ],
    primary: '#p1',
    success: '#ok',
    warning: '#warn',
    danger: '#bad',
    info: '#info',
    text: '#text',
    mutedText: '#muted',
    line: '#line',
    track: '#track',
    surface: '#surface',
    surfaceText: '#surfaceText',
};

function ctx(dir: 'ltr' | 'rtl' = 'ltr'): ChartContext {
    return {
        palette,
        dir,
        labels: {
            metres: 'Metres',
            date: 'Date',
            allocated: 'Allocated',
            capacity: 'Capacity',
            notSet: 'Not set',
            other: 'Other',
        },
        formatDate: (iso) => iso.split('-').reverse().join('-'),
    };
}

const dates = ['2026-09-01', '2026-09-02'];
const departments: ChartSeries[] = [
    {
        id: 1,
        key: 'department:1',
        name: 'Dyeing',
        total: 300,
        data: [100, 200],
    },
    { id: 2, key: 'department:2', name: 'Printing', total: 0, data: [0, 0] },
];

/**
 * One axis out of a built option. The return is a bag of unknowns rather than `any`: ECharts' own axis types
 * are a union wide enough that indexing them proves nothing, and a cast at each call site says out loud which
 * shape that assertion is standing in for.
 */
const axis = (
    option: unknown,
    key: 'xAxis' | 'yAxis',
): Record<string, unknown> =>
    (option as Record<string, Record<string, unknown>>)[key];

/** Every string in the option that the fake palette could have produced. */
function colours(value: unknown, found = new Set<string>()): string[] {
    if (typeof value === 'string') {
        if (value.startsWith('#')) {
            found.add(value);
        }
    } else if (Array.isArray(value)) {
        for (const item of value) {
            colours(item, found);
        }
    } else if (value !== null && typeof value === 'object') {
        for (const item of Object.values(value)) {
            colours(item, found);
        }
    }
    return [...found];
}

describe('departmentProductionOption', () => {
    it('paints from the palette, never from a literal', () => {
        const option = departmentProductionOption(dates, departments, ctx());
        const series = option.series as { itemStyle: { color: string } }[];

        expect(series[0].itemStyle.color).toBe(palette.series[0]);
        expect(
            (axis(option, 'yAxis').axisLabel as { color: string }).color,
        ).toBe(palette.mutedText);
        // Nothing may paint with a colour that did not come from the palette: the fake palette above uses
        // marker values, so any literal baked into the builder would show up here.
        expect(colours(option)).toEqual(
            expect.arrayContaining([
                palette.series[0],
                palette.mutedText,
                palette.line,
            ]),
        );
        for (const colour of colours(option)) {
            expect(Object.values(palette).flat()).toContain(colour);
        }
    });

    it('drops a department with nothing in the range rather than stacking an empty band', () => {
        const option = departmentProductionOption(dates, departments, ctx());
        expect(
            (option.series as { name: string }[]).map((s) => s.name),
        ).toEqual(['Dyeing']);
    });

    it('formats the x axis as dd-mm-yyyy', () => {
        expect(
            axis(departmentProductionOption(dates, departments, ctx()), 'xAxis')
                .data,
        ).toEqual(['01-09-2026', '02-09-2026']);
    });

    it('runs time and the value axis backwards under RTL, and flips the tooltip box', () => {
        const ltr = departmentProductionOption(dates, departments, ctx('ltr'));
        const rtl = departmentProductionOption(dates, departments, ctx('rtl'));

        expect(axis(ltr, 'xAxis').inverse).toBe(false);
        expect(axis(rtl, 'xAxis').inverse).toBe(true);
        expect(axis(ltr, 'yAxis').position).toBe('left');
        expect(axis(rtl, 'yAxis').position).toBe('right');
        expect(
            (rtl.tooltip as { extraCssText: string }).extraCssText,
        ).toContain('direction: rtl');
        expect(
            (ltr.tooltip as { extraCssText: string }).extraCssText,
        ).toContain('direction: ltr');
    });

    /**
     * The mill has twelve departments and the palette has ten colours. Painting with `index % length` gave the
     * eleventh department the first department's green — two bands in one stack a reader cannot tell apart,
     * which is exactly the thing a stacked bar is for.
     */
    describe('with more departments than the palette has colours', () => {
        const twelve: ChartSeries[] = Array.from({ length: 12 }, (_, i) => ({
            id: i + 1,
            key: `department:${i + 1}`,
            name: `Dept ${i + 1}`,
            // Descending, so "which are the smallest" is unambiguous.
            total: (12 - i) * 100,
            data: [(12 - i) * 40, (12 - i) * 60],
        }));

        it('never paints two bands the same colour', () => {
            const option = departmentProductionOption(dates, twelve, ctx());
            const drawn = option.series as unknown[];
            const used = option.color as string[];

            expect(used).toHaveLength(drawn.length);
            expect(new Set(used).size).toBe(drawn.length);
        });

        it('folds everything past the eighth department into one "other" band', () => {
            const names = (
                departmentProductionOption(dates, twelve, ctx()).series as {
                    name: string;
                }[]
            ).map((serie) => serie.name);

            expect(names).toHaveLength(MAX_SERIES + 1);
            expect(names.slice(0, MAX_SERIES)).toEqual([
                'Dept 1',
                'Dept 2',
                'Dept 3',
                'Dept 4',
                'Dept 5',
                'Dept 6',
                'Dept 7',
                'Dept 8',
            ]);
            expect(names.at(-1)).toBe('Other');
        });

        it('keeps the day totals whole: the other band carries the folded metres', () => {
            const series = departmentProductionOption(dates, twelve, ctx())
                .series as { data: number[] }[];
            const other = series.at(-1) as { data: number[] };

            // Departments 9–12: 4, 3, 2, 1 hundred total, split 40/60 per day.
            expect(other.data).toEqual([
                (4 + 3 + 2 + 1) * 40,
                (4 + 3 + 2 + 1) * 60,
            ]);
            expect(series.reduce((sum, serie) => sum + serie.data[0], 0)).toBe(
                twelve.reduce((sum, serie) => sum + serie.data[0], 0),
            );
        });

        it('leaves a short list alone rather than inventing an empty other band', () => {
            const names = (
                departmentProductionOption(dates, departments, ctx())
                    .series as { name: string }[]
            ).map((serie) => serie.name);

            expect(names).toEqual(['Dyeing']);
        });
    });

    it('adds a zoom only when the range is longer than a month', () => {
        const short = departmentProductionOption(dates, departments, ctx());
        const long = departmentProductionOption(
            Array.from(
                { length: 60 },
                (_, i) => `2026-09-${String((i % 28) + 1).padStart(2, '0')}`,
            ),
            departments,
            ctx(),
        );

        expect(short.dataZoom).toBeUndefined();
        expect((long.dataZoom as unknown[]).length).toBe(2);
    });
});

/**
 * The slider is a DOM-free part of the option, but its date labels are drawn by ECharts in its own light
 * defaults unless a `textStyle` overrides them — about 2.4:1 on this app's dark surface. The flow chart shipped
 * a bare `{ type: 'slider' }`, so the fix belongs to both builders or to neither.
 */
describe('the zoom slider', () => {
    const longRange = Array.from(
        { length: 60 },
        (_, i) => `2026-09-${String((i % 28) + 1).padStart(2, '0')}`,
    );

    const flow: ChartSeries[] = [
        { id: null, key: 'produced', name: 'Produced', total: 1, data: [1, 0] },
    ];

    it.each([
        [
            'departmentProductionOption',
            () => departmentProductionOption(longRange, departments, ctx()),
        ],
        ['flowOption', () => flowOption(longRange, flow, ctx())],
    ])('is themed by %s, not left to ECharts defaults', (_name, build) => {
        const slider = (build().dataZoom as Record<string, unknown>[])[1];

        expect(slider.type).toBe('slider');
        expect((slider.textStyle as { color: string }).color).toBe(
            palette.mutedText,
        );
        expect(slider.borderColor).toBe(palette.line);
        expect(slider.handleStyle).toEqual({ color: palette.primary });
    });
});

describe('flowOption', () => {
    it('gives each ledger flow its own semantic colour', () => {
        const flow: ChartSeries[] = [
            {
                id: null,
                key: 'produced',
                name: 'Produced',
                total: 1,
                data: [1, 0],
            },
            {
                id: null,
                key: 'dispatched',
                name: 'Dispatched',
                total: 1,
                data: [0, 1],
            },
            { id: null, key: 'loss', name: 'Loss', total: 1, data: [0, 1] },
        ];
        const series = flowOption(dates, flow, ctx()).series as {
            lineStyle: { color: string };
        }[];

        expect(series[0].lineStyle.color).toBe(palette.primary);
        expect(series[1].lineStyle.color).toBe(palette.success);
        expect(series[2].lineStyle.color).toBe(palette.danger);
    });
});

describe('wipStageOption', () => {
    it('folds the smallest stages into one bar rather than running out of colours', () => {
        // Stock + issued + twelve departments + finished: fifteen stages from a real mill.
        const stages = [
            { key: 'stock', name: 'Stock', qty: 900 },
            { key: 'issued', name: 'Issued', qty: 800 },
            ...Array.from({ length: 12 }, (_, i) => ({
                key: `department:${i + 1}`,
                name: `Dept ${i + 1}`,
                qty: 100 - i * 5,
            })),
            { key: 'finished', name: 'Finished', qty: 700 },
        ];
        const option = wipStageOption(stages, ctx());
        const names = axis(option, 'yAxis').data as string[];
        const colours = (
            option.series as { data: { itemStyle: { color: string } }[] }[]
        )[0].data.map((point) => point.itemStyle.color);

        expect(names).toHaveLength(MAX_SERIES + 1);
        // Reversed for a top-down read, so "Other" is the last bar and therefore the first entry.
        expect(names[0]).toBe('Other');
        expect(new Set(colours).size).toBe(colours.length);
        // Nothing is lost: the folded bar carries the metres of the stages it replaced.
        const total = stages.reduce((sum, stage) => sum + stage.qty, 0);
        const drawn = (
            option.series as { data: { value: number }[] }[]
        )[0].data.reduce((sum, point) => sum + point.value, 0);
        expect(drawn).toBe(total);
    });

    it('reverses the categories so the first stage reads at the top', () => {
        const option = wipStageOption(
            [
                { key: 'stock', name: 'Stock', qty: 10 },
                { key: 'finished', name: 'Finished', qty: 20 },
            ],
            ctx(),
        );
        expect(axis(option, 'yAxis').data).toEqual(['Finished', 'Stock']);
    });
});

const machines: UtilisationRow[] = [
    {
        id: 1,
        code: 'S1',
        name: 'Stenter 1',
        allocated_qty: 600,
        capacity_qty: 1000,
        free_qty: 400,
        utilisation_pct: 60,
        days: 1,
        uom: 'metres',
    },
    {
        id: 2,
        code: 'S2',
        name: 'Stenter 2',
        allocated_qty: 1250,
        capacity_qty: 1000,
        free_qty: -250,
        utilisation_pct: 125,
        days: 1,
        uom: 'metres',
    },
    {
        id: 3,
        code: 'JU',
        name: 'Jumbo',
        allocated_qty: 300,
        capacity_qty: null,
        free_qty: null,
        utilisation_pct: null,
        days: 1,
        uom: 'metres',
    },
];

describe('utilisationOption', () => {
    it('leaves a machine with no capacity out of the chart instead of drawing it at 0 or 100', () => {
        const option = utilisationOption(machines, ctx());
        expect(axis(option, 'yAxis').data).toEqual(['S2', 'S1']);
    });

    it('does not clamp an over-committed machine', () => {
        const option = utilisationOption(machines, ctx());
        const allocated = (option.series as { data: { value: number }[] }[])[1];
        // S2 comes first because the rows are reversed for a top-down read.
        expect(allocated.data[0].value).toBe(1250);
    });

    it('draws the capacity ghost behind the allocation', () => {
        const [capacity, allocated] = utilisationSeries();
        expect(capacity.itemStyle.color).toBe(palette.track);
        expect(allocated.barGap).toBe('-100%');
    });

    it('flips the value axis and the category side under RTL', () => {
        const rtl = utilisationOption(machines, ctx('rtl'));
        expect(axis(rtl, 'xAxis').inverse).toBe(true);
        expect(axis(rtl, 'yAxis').position).toBe('right');
    });
});

function utilisationSeries(): [
    { itemStyle: { color: string } },
    { barGap: string },
] {
    const series = utilisationOption(machines, ctx()).series as unknown[];
    return series as [{ itemStyle: { color: string } }, { barGap: string }];
}

describe('utilisation verdicts', () => {
    it('never claims to know an unknown figure', () => {
        expect(utilisationTone(null)).toBe('unknown');
        expect(utilisationColour(null, palette)).toBe(palette.mutedText);
    });

    it('reads over-commitment as a warning and idleness as spare', () => {
        expect(utilisationTone(125)).toBe('over');
        expect(utilisationColour(125, palette)).toBe(palette.danger);
        expect(utilisationTone(100)).toBe('high');
        expect(utilisationTone(85)).toBe('high');
        expect(utilisationTone(84.9)).toBe('good');
        expect(utilisationTone(59.9)).toBe('low');
        expect(utilisationColour(0, palette)).toBe(palette.info);
    });
});
