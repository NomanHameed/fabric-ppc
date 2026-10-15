import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { setTranslations } from '@/lib/i18n';
import { statusLine } from '@/lib/statusLine';
import type {
    FabricReportHeader,
    FabricReportStage,
    FabricReportTotals,
    FlatLotRow,
} from '@/types/production';

/*
 * R1 — the Month Wise Fabric Report screen. What only a mounted page can show:
 *
 *  - the Oracle Reports header block (spec §4.3): company, report title, the parameters it was run with,
 *    when it was run and by whom;
 *  - the stage grouping, with the flat payload's own columns beneath it;
 *  - both totals levels (stage, grand), each in the column it totals;
 *  - the truncation notice — without it, a stage subtotal with no rows beneath it reads as a bug;
 *  - the Forms toolbar with Save / New / Delete and record navigation off (a report writes nothing);
 *  - and query mode over the parameters: F7 opens them, F8 runs them.
 */

const get = vi.fn();
const visit = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ name: 'Head', setup: () => () => null }),
    Link: defineComponent({
        name: 'Link',
        setup:
            (_props, { slots }) =>
            () =>
                h('a', slots.default?.()),
    }),
    router: {
        get: (...args: unknown[]) => get(...args),
        visit: (...args: unknown[]) => visit(...args),
        on: () => () => {},
    },
}));

const EMPTY_TOTALS: FabricReportTotals = {
    lots: 0,
    greige_received_oracle: 0,
    greige_in_stock_oracle: 0,
    qty_issued: 0,
    wip: 0,
    finished: 0,
    dispatch: 0,
};

const flatRow = (overrides: Partial<FlatLotRow> = {}): FlatLotRow => ({
    party_oracle_id: 7731,
    party_oracle_name: 'ALKARAM TEXTILE',
    po_no: 261071,
    ref: 'REF-1',
    design_oracle_id: 'D-1143',
    color_oracle_id: 'C520',
    color_oracle_name: '520 PLUM',
    clr_status_oracle: 'DIP',
    po_type_oracle: 'DYED',
    date_oracle: '17-08-2026',
    lot_no_oracle: '264899',
    quality: '88X60/30X30 100% VISCOSE',
    gw_oracle: null,
    status_oracle: 'DYEING/',
    greige_received_oracle: 300,
    greige_in_stock_oracle: 10.5,
    qty_issued: 20.25,
    wip: 30,
    finished: 40,
    dispatch: 50,
    stage_id: 2,
    stage_name: 'Dyeing',
    stage_process_id: null,
    stage_process_name: null,
    lot_id: 7,
    ...overrides,
});

const stage = (
    over: Partial<FabricReportStage> & { code: string; name: string },
): FabricReportStage => ({
    ...EMPTY_TOTALS,
    id: 2,
    rows: [],
    ...over,
});

const header: FabricReportHeader = {
    company: 'ALBEEN TEXTILE MILLS',
    run_at: '09-10-2026 11:30',
    user: 'Noman',
};

/** One dyed lot under Dyeing, plus a Dispatch stage whose rows the cap hid. */
function report() {
    const dyed = flatRow();
    return {
        stages: [
            stage({
                id: 2,
                code: 'DYEING',
                name: 'Dyeing',
                lots: 1,
                greige_received_oracle: 300,
                greige_in_stock_oracle: 10.5,
                qty_issued: 20.25,
                wip: 30,
                finished: 40,
                dispatch: 50,
                rows: [dyed],
            }),
            // a stage the global cap left without rows: its subtotal still stands
            stage({
                id: 9,
                code: 'DISPATCH',
                name: 'Dispatch',
                lots: 1,
                dispatch: 200,
                rows: [],
            }),
            // a stage with no lots at all is not printed
            stage({ id: 3, code: 'PRINTING', name: 'Printing' }),
        ],
        totals: {
            lots: 2,
            greige_received_oracle: 300,
            greige_in_stock_oracle: 10.5,
            qty_issued: 20.25,
            wip: 30,
            finished: 40,
            dispatch: 250,
        },
        dyed,
    };
}

async function mountReport(extra: Record<string, unknown> = {}) {
    const { stages, totals } = report();
    const { default: FabricReport } =
        await import('@/pages/production/FabricReport.vue');
    const wrapper = mount(FabricReport, {
        attachTo: document.body,
        props: {
            stages,
            totals,
            rowsShown: 1,
            rowsTotal: 2,
            rowCap: 2000,
            months: [
                { value: '2026-09', label: 'Sep-26' },
                { value: '2026-08', label: 'Aug-26' },
            ],
            params: { month: null, process: 'all' },
            criteria: {},
            header,
            ...extra,
        },
    });
    await flushPromises();
    return wrapper;
}

const toolbarButton = (wrapper: ReturnType<typeof mount>, action: string) =>
    wrapper.get(`button[aria-label="forms.actions.${action}"]`);

beforeEach(() => {
    setTranslations('en', 'ltr', {
        production: {
            fabric_report: {
                title: 'Month Wise Fabric Report',
                month: 'Month',
                all: 'All',
                run_at: 'Run at',
                user: 'Run by',
                lots: 'Lots',
                stage_total: 'Subtotal :stage',
                grand_total: 'Grand total',
                showing: 'Showing :n of :m rows',
                truncated: 'Truncated at :cap rows: :m match, :n listed.',
                process: {
                    pending: 'Pending',
                    complete: 'Complete',
                    all: 'All',
                },
            },
            fields: {
                process: 'Process',
                stage: 'Stage',
            },
        },
    });
    get.mockClear();
    visit.mockClear();
    statusLine.reset();
});

describe('Fabric Report screen — the Oracle Reports frame', () => {
    it('prints the header block: company, title, the parameters used, the run stamp and the user', async () => {
        const wrapper = await mountReport({
            params: { month: '2026-08', process: 'pending' },
            criteria: { party: 'ALKARAM%' },
        });

        const head = wrapper.get('[data-testid="report-header"]');
        expect(wrapper.get('[data-testid="report-company"]').text()).toBe(
            'ALBEEN TEXTILE MILLS',
        );
        expect(head.text()).toContain('Month Wise Fabric Report');
        expect(head.text()).toContain('09-10-2026 11:30');
        expect(head.text()).toContain('Noman');

        // the parameters it was actually run with — the month by its Mmm-yy label, not its YYYY-MM value
        const params = wrapper.get('[data-testid="report-params"]').text();
        expect(params).toContain('Aug-26');
        expect(params).toContain('Pending');
        expect(params).toContain('ALKARAM%');

        wrapper.unmount();
    });

    it('names "All" when no month was chosen', async () => {
        const wrapper = await mountReport();

        expect(wrapper.get('[data-testid="report-params"]').text()).toContain(
            'All',
        );
        wrapper.unmount();
    });

    it('has Save, New, Delete and record navigation off, with Print and Exit live', async () => {
        const wrapper = await mountReport();

        for (const off of [
            'save',
            'newRecord',
            'deleteRecord',
            'previousRecord',
            'nextRecord',
        ]) {
            expect(
                toolbarButton(wrapper, off).attributes('disabled'),
            ).toBeDefined();
        }
        for (const on of ['print', 'exit', 'enterQuery', 'executeQuery']) {
            expect(
                toolbarButton(wrapper, on).attributes('disabled'),
            ).toBeUndefined();
        }
        // Clear only does something in query mode
        expect(
            toolbarButton(wrapper, 'clear').attributes('disabled'),
        ).toBeDefined();

        await toolbarButton(wrapper, 'exit').trigger('click');
        expect(visit).toHaveBeenCalledWith('/dashboard');

        wrapper.unmount();
    });
});

describe('Fabric Report screen — the grouped body', () => {
    it('groups by stage and draws the flat payload’s own columns', async () => {
        const wrapper = await mountReport();

        // the 21 visible columns of the flat payload, in its own order, each header through t()
        const headers = wrapper.findAll('thead th').map((th) => th.text());
        expect(headers).toHaveLength(21);
        expect(headers[0]).toBe('production.flat.lot_no_oracle');
        expect(headers.at(-1)).toBe('production.flat.dispatch');

        // the one group header: the stage
        expect(
            wrapper.get('[data-testid="stage-head-DYEING"]').text(),
        ).toContain('Dyeing');

        // the lot's own row, keyed by its lot id, with metres at 2 dp and a null width left blank
        const cells = wrapper
            .get('[data-testid="lot-row-7"]')
            .findAll('td')
            .map((td) => td.text());
        expect(cells[0]).toBe('264899');
        expect(cells[5]).toBe('17-08-2026');
        expect(cells[12]).toBe(''); // gw_oracle is null — not measured is not zero
        expect(cells.at(-1)).toBe('50.00');

        // a stage with no lots is not printed at all
        expect(
            wrapper.find('[data-testid="stage-head-PRINTING"]').exists(),
        ).toBe(false);

        wrapper.unmount();
    });

    it('prints a subtotal per stage and a grand total', async () => {
        const wrapper = await mountReport();

        const total = (testid: string) =>
            wrapper
                .get(`[data-testid="${testid}"]`)
                .findAll('td')
                .slice(1)
                .map((td) => td.text());

        // the six report quantities, in the order of the columns they sit under
        expect(total('stage-total-DYEING')).toEqual([
            '300.00',
            '10.50',
            '20.25',
            '30.00',
            '40.00',
            '50.00',
        ]);
        expect(total('grand-total')).toEqual([
            '300.00',
            '10.50',
            '20.25',
            '30.00',
            '40.00',
            '250.00',
        ]);

        // each total is in the column it totals
        expect(wrapper.get('[data-testid="grand-total-dispatch"]').text()).toBe(
            '250.00',
        );
        expect(
            wrapper.get('[data-testid="stage-total-DYEING-wip"]').text(),
        ).toBe('30.00');

        // and the labels name their group
        expect(
            wrapper.get('[data-testid="stage-total-DYEING"]').text(),
        ).toContain('Subtotal Dyeing');
        expect(wrapper.get('[data-testid="grand-total"]').text()).toContain(
            'Grand total',
        );

        wrapper.unmount();
    });

    it('names the truncation, and the stage whose rows the cap hid', async () => {
        const wrapper = await mountReport();

        const notice = wrapper.get('[data-testid="truncation-notice"]');
        expect(notice.text()).toBe(
            'Truncated at 2000 rows: 2 match, 1 listed.',
        );

        // the Dispatch stage still prints its subtotal, with the empty-rows explanation in its place
        expect(
            wrapper.find('[data-testid="stage-empty-DISPATCH"]').exists(),
        ).toBe(true);
        expect(
            wrapper.get('[data-testid="stage-total-DISPATCH-dispatch"]').text(),
        ).toBe('200.00');

        // the status line is the report's record counter: rows listed of lots matched
        expect(statusLine.state.recordIndex).toBe(1);
        expect(statusLine.state.recordCount).toBe(2);
        expect(statusLine.state.hint).toBe('Showing 1 of 2 rows');

        wrapper.unmount();
    });

    it('says nothing about truncation when every match was listed', async () => {
        const wrapper = await mountReport({ rowsShown: 2, rowsTotal: 2 });

        expect(wrapper.find('[data-testid="truncation-notice"]').exists()).toBe(
            false,
        );
        expect(statusLine.state.hint).toBe('');
        wrapper.unmount();
    });

    it('shows the empty message when nothing matched', async () => {
        const wrapper = await mountReport({
            stages: [],
            rowsShown: 0,
            rowsTotal: 0,
        });

        expect(wrapper.find('[data-testid="report-table"]').exists()).toBe(
            false,
        );
        expect(wrapper.find('[data-testid="no-rows"]').exists()).toBe(true);
        wrapper.unmount();
    });
});

describe('Fabric Report screen — the parameters', () => {
    it('enters query mode over the parameters and runs them with Execute Query', async () => {
        const wrapper = await mountReport();

        // the parameter boxes are not on screen until F7 opens them
        expect(wrapper.find('#q-party').exists()).toBe(false);

        await toolbarButton(wrapper, 'enterQuery').trigger('click');
        await flushPromises();

        for (const f of [
            'party',
            'po_type_oracle',
            'design_oracle_id',
            'stage',
        ]) {
            expect(wrapper.find(`#q-${f}`).exists()).toBe(true);
        }
        // Clear is live in query mode, and leaves it again
        expect(
            toolbarButton(wrapper, 'clear').attributes('disabled'),
        ).toBeUndefined();

        await wrapper.get('#q-party').setValue('alkaram%');
        await wrapper.get('#q-design_oracle_id').setValue('D-1143');
        await toolbarButton(wrapper, 'executeQuery').trigger('click');
        await flushPromises();

        expect(get).toHaveBeenCalledTimes(1);
        expect(get.mock.calls[0][0]).toBe('/production/reports/fabric');
        expect(get.mock.calls[0][1]).toEqual({
            q: { party: 'alkaram%', design_oracle_id: 'D-1143' },
            process: 'all',
        });
        // a report is a link someone can keep, so the visit is a partial reload of the report's own props
        expect((get.mock.calls[0][2] as { only: string[] }).only).toContain(
            'stages',
        );
        expect((get.mock.calls[0][2] as { only: string[] }).only).toContain(
            'totals',
        );

        wrapper.unmount();
    });

    it('leaves query mode on Clear without running anything', async () => {
        const wrapper = await mountReport();

        await toolbarButton(wrapper, 'enterQuery').trigger('click');
        await wrapper.get('#q-party').setValue('alkaram%');
        await toolbarButton(wrapper, 'clear').trigger('click');
        await flushPromises();

        expect(wrapper.find('#q-party').exists()).toBe(false);
        expect(get).not.toHaveBeenCalled();
        wrapper.unmount();
    });

    it('re-runs the report as soon as Month or Process changes', async () => {
        const wrapper = await mountReport({
            params: { month: null, process: 'pending' },
            criteria: { party: 'ALKARAM%' },
        });

        const month = wrapper.get('[data-testid="param-month"]');
        expect(
            month.findAll('option').map((o) => o.attributes('value')),
        ).toEqual(['', '2026-09', '2026-08']);

        await month.setValue('2026-08');
        await flushPromises();
        expect(get.mock.calls[0][1]).toEqual({
            q: { party: 'ALKARAM%' },
            month: '2026-08',
            process: 'pending',
        });

        get.mockClear();
        const proc = wrapper.get('[data-testid="param-process"]');
        expect(
            proc.findAll('option').map((o) => o.attributes('value')),
        ).toEqual(['pending', 'complete', 'all']);
        await proc.setValue('complete');
        await flushPromises();
        // "All" months drops the parameter from the URL rather than sending an empty one
        expect(get.mock.calls[0][1]).toEqual({
            q: { party: 'ALKARAM%' },
            process: 'complete',
        });

        wrapper.unmount();
    });
});
