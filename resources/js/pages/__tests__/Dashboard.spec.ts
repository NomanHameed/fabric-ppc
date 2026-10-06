import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { setTranslations } from '@/lib/i18n';

/*
 * The mill's dashboard.
 *
 * Its sharpest failure mode is invisible: every filter control re-issues the whole read, and the server takes
 * longer over a quarter than over a week, so the answers do not come back in the order they were asked for. A
 * slow "last 90 days" landing after a fast "this week" used to paint the quarter's figures under the week's
 * filters — a wrong dashboard that looks exactly like a right one. Nobody would ever report it as a bug.
 * Hence the first describe below: two requests, answered out of order, and only the newer one is allowed to
 * reach the screen. The second request is driven by a language change rather than a filter button, because
 * the buttons disable themselves while a load is in flight — the locale watcher does not, which is exactly
 * why it is the path that can actually overlap today.
 */
const { getJson, permissions, screen } = vi.hoisted(() => ({
    getJson: vi.fn(),
    permissions: { list: ['inquiry.view', 'production.sheet.view'] },
    /** Plain state, not a ref: `vi.hoisted` runs before Vue itself is imported. */
    screen: { wide: true },
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ name: 'Head', setup: () => () => null }),
    Link: defineComponent({
        name: 'Link',
        setup:
            (_props, { slots }) =>
            () =>
                h('a', slots.default?.()),
    }),
}));

/** Partial: the shadcn primitives pull other helpers out of the same module. */
vi.mock('@vueuse/core', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@vueuse/core')>();
    const { computed } = await import('vue');

    return { ...actual, useMediaQuery: () => computed(() => screen.wide) };
});

vi.mock('@/lib/api', () => ({
    getJson: (...args: unknown[]) => getJson(...args),
    isAbortError: (error: unknown) =>
        typeof error === 'object' &&
        error !== null &&
        (error as { name?: unknown }).name === 'AbortError',
}));

vi.mock('@/lib/can', () => ({
    can: (permission: string) => permissions.list.includes(permission),
}));

/** The real BaseChart runs; only the canvas itself is stubbed, so jsdom never meets ECharts. */
vi.mock('@/components/charts/echarts', () => ({
    VChart: defineComponent({
        name: 'VChart',
        props: { option: { type: Object, default: () => ({}) } },
        setup: () => () => h('div', { 'data-testid': 'chart-canvas' }),
    }),
}));

const stats = {
    open_lots: 42,
    open_contracts: 9,
    open_greige_qty: 125000,
    parties: 17,
    missing_from_oracle: 0,
};

type Analytics = ReturnType<typeof analytics>;

function analytics(
    from: string,
    to: string,
    departments: { id: number; name: string; total: number }[] = [
        { id: 1, name: 'Dyeing', total: 300 },
    ],
) {
    return {
        range: { from, to, days: 2 },
        dates: ['2026-09-01', '2026-09-02'],
        by_department: departments.map((d) => ({
            id: d.id,
            key: `department:${d.id}`,
            name: d.name,
            total: d.total,
            data: [d.total / 2, d.total / 2],
        })),
        flow: [
            {
                id: null,
                key: 'produced',
                name: 'Produced',
                total: 300,
                data: [150, 150],
            },
        ],
        wip_by_stage: [{ key: 'stock', name: 'Stock', qty: 500 }],
        kpi: {
            produced: 300,
            issued: 100,
            dispatched: 50,
            loss: 0,
            wip: 500,
        },
        utilisation: { machines: [], departments: [] },
        filters: {
            departments: [
                { id: 1, code: 'DY', name: 'Dyeing' },
                { id: 2, code: 'PR', name: 'Printing' },
            ],
            operations: [
                { id: 11, name: 'Jet dyeing', department_id: 1 },
                { id: 22, name: 'Rotary print', department_id: 2 },
            ],
            applied: { department_id: null, operation_id: null },
        },
    };
}

async function mountDashboard(response?: Analytics) {
    if (response !== undefined) {
        getJson.mockResolvedValue(response);
    }
    const { default: Dashboard } = await import('@/pages/Dashboard.vue');
    const wrapper = mount(Dashboard, {
        attachTo: document.body,
        props: { stats, lastImport: null },
    });
    await flushPromises();

    return wrapper;
}

/** Only the one line whose wording is under test; every other key falls through to itself. */
const DICT = { dashboard: { filters: { range: ':from to :to' } } };

beforeEach(() => {
    getJson.mockReset();
    setTranslations('en', 'ltr', DICT, 'test');
    permissions.list = ['inquiry.view', 'production.sheet.view'];
    screen.wide = true;
    window.history.replaceState({}, '', '/dashboard');
});
afterEach(() => {
    document.body.innerHTML = '';
});

describe('Dashboard — out-of-order responses', () => {
    it('shows the newer request even when the older one answers last', async () => {
        // Two pending reads, resolved deliberately in the wrong order.
        let resolveFirst: (v: Analytics) => void = () => {};
        let resolveSecond: (v: Analytics) => void = () => {};
        getJson
            .mockImplementationOnce(
                () =>
                    new Promise<Analytics>((resolve) => {
                        resolveFirst = resolve;
                    }),
            )
            .mockImplementationOnce(
                () =>
                    new Promise<Analytics>((resolve) => {
                        resolveSecond = resolve;
                    }),
            );

        const wrapper = await mountDashboard();

        // A second request while the first is still in flight.
        setTranslations('ur', 'rtl', DICT, 'test');
        await flushPromises();
        expect(getJson).toHaveBeenCalledTimes(2);

        resolveSecond(analytics('2026-03-01', '2026-03-07'));
        await flushPromises();
        resolveFirst(analytics('2020-01-01', '2020-12-31'));
        await flushPromises();

        // The newer answer stands. The older one is not merely late — it is not the question being asked.
        expect(wrapper.get('#f-from').element).toHaveProperty(
            'value',
            '2026-03-01',
        );
        expect(wrapper.text()).toContain('01-03-2026');
        expect(wrapper.text()).not.toContain('01-01-2020');
        wrapper.unmount();
    });

    it('lets a stale failure neither raise nor clear the failure strip', async () => {
        let failLate: (e: Error) => void = () => {};
        getJson
            .mockImplementationOnce(
                () =>
                    new Promise((_resolve, reject) => {
                        failLate = reject;
                    }),
            )
            .mockResolvedValue(analytics('2026-03-01', '2026-03-07'));

        const wrapper = await mountDashboard();
        setTranslations('ur', 'rtl', DICT, 'test');
        await flushPromises();

        failLate(new Error('the superseded request'));
        await flushPromises();

        expect(wrapper.find('[data-test="charts-failed"]').exists()).toBe(
            false,
        );
        wrapper.unmount();
    });

    it('still reports a genuine failure of the newest request', async () => {
        getJson.mockRejectedValue(new Error('network'));
        const wrapper = await mountDashboard();

        expect(wrapper.find('[data-test="charts-failed"]').exists()).toBe(true);

        getJson.mockResolvedValue(analytics('2026-03-01', '2026-03-07'));
        await wrapper.get('[data-test="charts-retry"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-test="charts-failed"]').exists()).toBe(
            false,
        );
        wrapper.unmount();
    });
});

describe('Dashboard — the department shares', () => {
    it('divides by no zero when the range produced nothing', async () => {
        const wrapper = await mountDashboard(
            analytics('2026-09-01', '2026-09-02', [
                { id: 1, name: 'Dyeing', total: 0 },
            ]),
        );

        // Not NaN%, not Infinity: a range with no production has no shares to show.
        expect(wrapper.text()).not.toContain('NaN');
        expect(wrapper.text()).not.toContain('Infinity');
        expect(wrapper.text()).toContain('dashboard.charts.empty');
        wrapper.unmount();
    });

    it('splits a real total into shares that read as the bars beside it', async () => {
        const wrapper = await mountDashboard(
            analytics('2026-09-01', '2026-09-02', [
                { id: 1, name: 'Dyeing', total: 300 },
                { id: 2, name: 'Printing', total: 100 },
            ]),
        );

        const widths = wrapper
            .findAll('li div[style]')
            .map((bar) => bar.attributes('style'));

        expect(widths).toEqual(
            expect.arrayContaining([
                expect.stringContaining('75%'),
                expect.stringContaining('25%'),
            ]),
        );
        wrapper.unmount();
    });
});

describe('Dashboard — the department/process cascade', () => {
    it('lists only the processes of the chosen department, and clears a stale one', async () => {
        const wrapper = await mountDashboard(
            analytics('2026-09-01', '2026-09-02'),
        );

        const processes = () =>
            wrapper
                .get('#f-process')
                .findAll('option')
                .map((o) => o.text().trim());

        expect(processes()).toEqual([
            'dashboard.filters.all_processes',
            'Jet dyeing',
            'Rotary print',
        ]);

        await wrapper.get('#f-process').setValue('22');
        await wrapper.get('#f-dept').setValue('1');
        await flushPromises();

        // The process that belonged to the other department is gone from the list *and* from the filter.
        expect(processes()).toEqual([
            'dashboard.filters.all_processes',
            'Jet dyeing',
        ]);
        expect(wrapper.get('#f-process').element).toHaveProperty('value', '');

        await wrapper.get('[data-test="apply"]').trigger('click');
        await flushPromises();
        expect(String(getJson.mock.calls.at(-1)?.[0])).not.toContain(
            'operation_id',
        );
        wrapper.unmount();
    });
});

describe('Dashboard — a viewer without the production read', () => {
    it('keeps the standing counts and draws no chart at all', async () => {
        permissions.list = [];
        const wrapper = await mountDashboard(
            analytics('2026-09-01', '2026-09-02'),
        );

        // The counts are Inertia props, not part of the analytics read.
        expect(wrapper.text()).toContain('dashboard.open_lots');
        expect(wrapper.text()).toContain('42');
        expect(wrapper.find('[data-testid="chart-canvas"]').exists()).toBe(
            false,
        );
        expect(wrapper.find('#f-from').exists()).toBe(false);
        wrapper.unmount();
    });
});
