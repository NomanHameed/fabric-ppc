import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';

/*
 * The capacity screen's whole job is to not invent a number.
 *
 * A machine with no capacity on file reads "not set" — never 0%, never 100% — and it is listed in the table
 * but left out of the chart, because a bar has to have a length and there is no honest one to give it. The
 * footnote under the table explains exactly that, so the table and the footnote have to agree: if a row reads
 * "0 m capacity, -300 m free, Not set" while the footnote says the not-set machines are the ones without a
 * capacity, the screen is contradicting its own explanation and the reader cannot tell which half to believe.
 */
const { getJson, screen } = vi.hoisted(() => ({
    getJson: vi.fn(),
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

/** Partial: the shadcn primitives below pull other helpers out of the same module. */
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

vi.mock('@/lib/can', () => ({ can: () => true }));

/**
 * The real `BaseChart` runs, with only the ECharts canvas itself stubbed out: that keeps the empty/label
 * wiring under test while keeping a WebGL-less jsdom out of a 600 kB charting library. The option object
 * itself is covered by `options.spec.ts`.
 */
vi.mock('@/components/charts/echarts', () => ({
    VChart: defineComponent({
        name: 'VChart',
        props: { option: { type: Object, default: () => ({}) } },
        setup: () => () => h('div', { 'data-testid': 'chart-canvas' }),
    }),
}));

type Row = {
    id: number;
    code: string;
    name: string;
    allocated_qty: number;
    capacity_qty: number | null;
    free_qty: number | null;
    utilisation_pct: number | null;
    days: number;
    uom: string;
};

function row(overrides: Partial<Row> = {}): Row {
    return {
        id: 1,
        code: 'S1',
        name: 'Stenter 1',
        allocated_qty: 600,
        capacity_qty: 1000,
        free_qty: 400,
        utilisation_pct: 60,
        days: 30,
        uom: 'metres',
        ...overrides,
    };
}

/**
 * The payload a machine with `capacity_qty = 0` produces today.
 *
 * `Utilisation::percent()` already treats a capacity of 0 as unknown and returns null, but `freeQty()` does
 * not — it returns `0 - allocated` — and `capacity_qty` is serialised as the literal 0. That asymmetry is what
 * put "0" and "-300" in a row the same table called "not set".
 */
const zeroCapacity = row({
    id: 3,
    code: 'JU',
    name: 'Jumbo',
    allocated_qty: 300,
    capacity_qty: 0,
    free_qty: -300,
    utilisation_pct: null,
});

function payload(machines: Row[], departments: Row[] = []) {
    return {
        range: { from: '2026-09-01', to: '2026-09-30' },
        machines,
        departments,
        department: null,
    };
}

/**
 * Settles both the figures request and the lazily-imported chart component: `BaseChart` is behind
 * `defineAsyncComponent`, so its module resolves a few microtasks after the page itself mounts.
 */
async function settle(): Promise<void> {
    // Real ticks, not just a microtask drain: the runner reads the chart module off disk.
    for (let i = 0; i < 50; i += 1) {
        await new Promise((resolve) => setTimeout(resolve, 0));
        await flushPromises();
        if (document.body.querySelector('[data-testid="chart-canvas"]')) {
            return;
        }
    }
}

async function mountScreen(response: unknown = payload([row()])) {
    getJson.mockResolvedValue(response);
    const { default: Utilisation } =
        await import('@/pages/production/Utilisation.vue');
    const wrapper = mount(Utilisation, {
        attachTo: document.body,
        props: { departments: [{ id: 1, code: 'DY', name: 'Dyeing' }] },
    });
    await settle();

    return wrapper;
}

/** The cells of one machine row, in column order: code, name, allocated, capacity, free, utilisation. */
function cells(
    wrapper: { findAll: (s: string) => { text: () => string }[] },
    index = 0,
): string[] {
    const rows = wrapper.findAll('tbody tr');

    return (
        rows[index] as unknown as {
            findAll: (s: string) => { text: () => string }[];
        }
    )
        .findAll('td')
        .map((cell) => cell.text().trim());
}

beforeEach(() => {
    getJson.mockReset();
    screen.wide = true;
    window.history.replaceState({}, '', '/production/capacity');
});
afterEach(() => {
    document.body.innerHTML = '';
});

describe('Utilisation — a machine whose capacity is zero', () => {
    it('reads "not set" in capacity, free and utilisation alike', async () => {
        const wrapper = await mountScreen(payload([zeroCapacity]));
        const [, , allocated, capacity, free, utilisation] = cells(wrapper);

        expect(allocated).toBe('300');
        // Not "0", not "-300": the server already calls this utilisation unknown.
        expect(capacity).toBe('production.capacity.not_set');
        expect(free).toBe('production.capacity.not_set');
        expect(utilisation).toBe('production.capacity.not_set');
        wrapper.unmount();
    });

    it('agrees with the footnote: it is counted as uncharted', async () => {
        const wrapper = await mountScreen(payload([row(), zeroCapacity]));

        expect(wrapper.text()).toContain('production.capacity.not_set_hint');
        expect(wrapper.find('[data-testid="chart-canvas"]').exists()).toBe(
            true,
        );
        wrapper.unmount();
    });

    it('still shows real figures for a machine that does have a capacity', async () => {
        const wrapper = await mountScreen(payload([row()]));
        const [, , allocated, capacity, free] = cells(wrapper);

        expect(allocated).toBe('600');
        expect(capacity).toBe('1,000');
        expect(free).toBe('400');
        wrapper.unmount();
    });

    it('keeps a negative free figure when the capacity is real and over-committed', async () => {
        const wrapper = await mountScreen(
            payload([
                row({
                    allocated_qty: 1250,
                    capacity_qty: 1000,
                    free_qty: -250,
                    utilisation_pct: 125,
                }),
            ]),
        );

        // Over-commitment is a fact the planner needs, not an unknown.
        expect(cells(wrapper)[4]).toBe('-250');
        wrapper.unmount();
    });
});

describe('Utilisation — the footnote', () => {
    it('appears only when some machine is listed but not charted', async () => {
        const all = await mountScreen(payload([row()]));
        expect(all.text()).not.toContain('production.capacity.not_set_hint');
        all.unmount();

        const some = await mountScreen(payload([row(), zeroCapacity]));
        expect(some.text()).toContain('production.capacity.not_set_hint');
        some.unmount();
    });

    it('empties the chart when nothing at all can be drawn', async () => {
        const wrapper = await mountScreen(payload([zeroCapacity]));

        // Nothing drawable: the empty state stands in for the canvas and says why.
        expect(wrapper.find('[data-testid="chart-canvas"]').exists()).toBe(
            false,
        );
        expect(wrapper.text()).toContain('production.capacity.not_set_hint');
        wrapper.unmount();
    });
});

describe('Utilisation — the filters', () => {
    it('asks the server exactly once per control', async () => {
        const wrapper = await mountScreen();
        expect(getJson).toHaveBeenCalledTimes(1);

        await wrapper.get('[data-test="apply"]').trigger('click');
        await flushPromises();
        expect(getJson).toHaveBeenCalledTimes(2);

        await wrapper.get('[data-test="reset"]').trigger('click');
        await flushPromises();
        expect(getJson).toHaveBeenCalledTimes(3);

        await wrapper.get('[data-test="preset-week"]').trigger('click');
        await flushPromises();
        expect(getJson).toHaveBeenCalledTimes(4);

        // Nothing is ever narrowed in the browser: every call carries the range as query parameters.
        for (const call of getJson.mock.calls) {
            expect(String(call[0])).toMatch(
                /^\/production\/utilisation\?from=\d{4}-\d{2}-\d{2}&to=\d{4}-\d{2}-\d{2}/,
            );
        }
        wrapper.unmount();
    });

    it('adopts the range the server actually ran and says that it narrowed it', async () => {
        window.history.replaceState(
            {},
            '',
            '/production/capacity?from=2024-01-01&to=2026-12-31',
        );
        const wrapper = await mountScreen(payload([row()]));

        expect(wrapper.find('[data-test="range-clamped"]').exists()).toBe(true);
        expect((wrapper.get('#u-from').element as HTMLInputElement).value).toBe(
            '2026-09-01',
        );
        expect((wrapper.get('#u-to').element as HTMLInputElement).value).toBe(
            '2026-09-30',
        );
        wrapper.unmount();
    });

    it('says nothing when the server ran the range it was given', async () => {
        window.history.replaceState(
            {},
            '',
            '/production/capacity?from=2026-09-01&to=2026-09-30',
        );
        const wrapper = await mountScreen(payload([row()]));

        expect(wrapper.find('[data-test="range-clamped"]').exists()).toBe(
            false,
        );
        wrapper.unmount();
    });
});

describe('Utilisation — when the request fails', () => {
    it('offers a retry and clears the failure once it works', async () => {
        getJson.mockRejectedValueOnce(new Error('network'));
        const { default: Utilisation } =
            await import('@/pages/production/Utilisation.vue');
        const wrapper = mount(Utilisation, {
            attachTo: document.body,
            props: { departments: [] },
        });
        await settle();

        expect(wrapper.text()).toContain('production.capacity.failed');
        expect(wrapper.find('tbody tr td[colspan="6"]').exists()).toBe(true);

        getJson.mockResolvedValue(payload([row()]));
        await wrapper.get('[data-test="retry"]').trigger('click');
        await flushPromises();

        expect(wrapper.text()).not.toContain('production.capacity.failed');
        expect(cells(wrapper)[0]).toBe('S1');
        wrapper.unmount();
    });
});

describe('Utilisation — narrow screens', () => {
    it('swaps the canvas for the bar summary, and never shows both', async () => {
        screen.wide = false;
        const wrapper = await mountScreen(payload([row()]));

        expect(wrapper.find('[data-testid="chart-canvas"]').exists()).toBe(
            false,
        );
        // The machine's own figure, as real text rather than a canvas.
        expect(wrapper.text()).toContain('S1 — Stenter 1');
        wrapper.unmount();

        screen.wide = true;
        const desktop = await mountScreen(payload([row()]));
        expect(desktop.find('[data-testid="chart-canvas"]').exists()).toBe(
            true,
        );
        desktop.unmount();
    });
});
