import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';
import { setTranslations } from '@/lib/i18n';
import type {
    FlatLotRow,
    LotGridShape,
    LotScreenRow,
} from '@/types/production';

/*
 * The Lots screen's Oracle-Forms guarantees that only a mounted page can show: a state action (Hold /
 * Release / Cancel) is a POST, which the unsaved guard's router hook cannot intercept, so it must go through
 * `guard.guard()` itself; Save is off for anyone who may not write, and while query mode is open; and the
 * reporting stage is read-only until the operator explicitly takes the override, because it is a figure the
 * ledger derives.
 */

type Fields = Record<string, unknown>;

const put = vi.fn();
const post = vi.fn();
const get = vi.fn();
const del = vi.fn();
const visit = vi.fn();

/** Enough of Inertia's `useForm` for this page: the given fields, `isDirty` against the current defaults. */
function makeForm(initial: Fields) {
    let defaults: Fields = { ...initial };
    const form = reactive({
        ...initial,
        errors: {} as Record<string, string>,
        get isDirty(): boolean {
            return Object.keys(defaults).some(
                (key) => (form as Fields)[key] !== defaults[key],
            );
        },
        defaults(next: Fields): void {
            defaults = { ...next };
        },
        reset(): void {
            Object.assign(form, defaults);
        },
        clearErrors(): void {
            form.errors = {};
        },
        setError(errors: Record<string, string>): void {
            Object.assign(form.errors, errors);
        },
        put,
    });
    return form;
}

/** A dialog stub that only says whether it is open — the real ones are covered by their own specs. */
function dialogStub(name: string, testid: string) {
    return {
        default: defineComponent({
            name,
            props: { open: { type: Boolean, default: false } },
            setup: (props) => () =>
                props.open ? h('div', { 'data-testid': testid }) : null,
        }),
    };
}

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
        post: (...args: unknown[]) => post(...args),
        delete: (...args: unknown[]) => del(...args),
        visit: (...args: unknown[]) => visit(...args),
        on: () => () => {},
    },
    useForm: (initial: Fields) => makeForm(initial),
}));

/**
 * The two selects read their options from the API; here they stand in as plain selects so the page's own
 * behaviour (read-only until the override is taken) is what is under test. Their own specs cover the loading.
 */
function selectStub(name: string, testid: string) {
    return {
        default: defineComponent({
            name,
            props: {
                modelValue: { type: Number, default: null },
                disabled: { type: Boolean, default: false },
            },
            emits: ['update:modelValue'],
            setup:
                (props, { emit }) =>
                () =>
                    h('select', {
                        'data-testid': testid,
                        disabled: props.disabled,
                        onChange: (e: Event) =>
                            emit(
                                'update:modelValue',
                                Number((e.target as HTMLSelectElement).value),
                            ),
                    }),
        }),
    };
}

vi.mock('@/components/stages/StageSelect.vue', () =>
    selectStub('StageSelect', 'stage-select'),
);

/**
 * Every DataGrid the page mounted, in order, with the props it was given. The real grid is AG Grid, so the
 * columns it was handed are the only place the flat shape's column set can be asserted.
 */
const grids: { rows: unknown[]; columns: ColumnLike[] }[] = [];
type ColumnLike = {
    field?: string;
    headerName?: string;
    valueFormatter?: (p: { value: unknown }) => string;
};

vi.mock('@/components/forms/DataGrid.vue', () => ({
    default: defineComponent({
        name: 'DataGrid',
        props: {
            rows: { type: Array, default: () => [] },
            columns: { type: Array, default: () => [] },
        },
        setup: (props) => {
            grids.push(
                props as unknown as { rows: unknown[]; columns: ColumnLike[] },
            );
            return () => null;
        },
    }),
}));
vi.mock('@/components/forms/ConfirmSaveDialog.vue', () =>
    dialogStub('ConfirmSaveDialog', 'unsaved-dialog'),
);
vi.mock('@/components/forms/ConfirmDialog.vue', () =>
    dialogStub('ConfirmDialog', 'confirm-dialog'),
);
vi.mock('@/components/forms/ReasonDialog.vue', () =>
    dialogStub('ReasonDialog', 'reason-dialog'),
);

const lot: LotScreenRow = {
    id: 7,
    lot_no_oracle: '264899',
    contract_id: 3,
    po_no: 261071,
    party: 'NORMAL PARTY',
    ref_no: 'REF-1',
    po_date: '17-08-2026',
    quality: '88X60/30X30 100% VISCOSE',
    gsm: null,
    design: 'E1143/119',
    color_oracle_name: '520 PLUM',
    clr_status_oracle: 'DIP',
    gw_oracle: null,
    f_width: null,
    po_type_oracle: 'DYED',
    state: 'open',
    hold_reason: null,
    cancel_reason: null,
    state_changed_at: null,
    status_oracle: null,
    process_flag: 'pending',
    stage_id: null,
    stage: null,
    stage_overridden: false,
    stage_overridden_at: null,
    stage_overridden_by: null,
    greige_received_oracle: 300,
    greige_in_stock_oracle: 300,
    qty_issued: 0,
    wip: 0,
    finished: 0,
    dispatch: 0,
    loss_qty: 0,
    last_movement_at: null,
    oracle_missing_at: null,
    remarks: null,
};

/** The flat, Oracle-shaped payload of the same lot: the mill's own field names over the same columns. */
const flatLot: FlatLotRow = {
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
    finished: 0,
    dispatch: 0,
    stage_id: 2,
    stage_name: 'Dyeing',
    stage_process_id: null,
    stage_process_name: null,
    lot_id: 7,
};

async function mountLots(
    can = { colour: true, remarks: true, hold: true, route: true, stage: true },
    rows: LotScreenRow[] = [lot],
    extra: { shape?: LotGridShape; flatLots?: FlatLotRow[] } = {},
) {
    const { default: Lots } = await import('@/pages/production/Lots.vue');
    const wrapper = mount(Lots, {
        attachTo: document.body,
        props: {
            lots: rows,
            lotsTotal: 1,
            colourStatuses: [
                { value: 'DIP', label: 'Dip', approved: false },
                { value: 'APPROVED', label: 'Approved', approved: true },
            ],
            holdReasons: [{ value: 'QUALITY', label: 'Quality issue' }],
            can,
            criteria: {},
            ...extra,
        },
    });
    await flushPromises();
    return wrapper;
}

/** The toolbar's buttons carry the action's label; the block's own buttons carry their text. */
const toolbarButton = (wrapper: ReturnType<typeof mount>, action: string) =>
    wrapper.get(`button[aria-label="forms.actions.${action}"]`);
const textButton = (wrapper: ReturnType<typeof mount>, label: string) =>
    wrapper.findAll('button').find((b) => b.text() === label) as ReturnType<
        typeof wrapper.get
    >;

beforeEach(() => {
    // Only the line whose :user / :at placeholders are the point; every other string stays its own key,
    // which keeps the assertions below about i18n going through t() at all.
    setTranslations('en', 'ltr', {
        production: {
            lots: {
                stage_overridden_note: 'Pinned by :user on :at',
            },
        },
    });
    put.mockClear();
    post.mockClear();
    get.mockClear();
    del.mockClear();
    visit.mockClear();
    grids.length = 0;
    // Inertia always runs onFinish, whatever the server said; without it the page would stay "busy" and its
    // own buttons would be disabled for the rest of the test.
    const finish = (_url: unknown, opts?: { onFinish?: () => void }) =>
        opts?.onFinish?.();
    put.mockImplementation(finish);
    del.mockImplementation(finish);
    post.mockImplementation(finish);
});

afterEach(() => {
    document.body.innerHTML = '';
});

describe('Lots screen', () => {
    it('sends Hold through the unsaved guard: the prompt opens and nothing is posted yet', async () => {
        const wrapper = await mountLots();
        expect(wrapper.find('[data-testid="unsaved-dialog"]').exists()).toBe(
            false,
        );

        await wrapper.get('#lot-remarks').setValue('rush this lot');
        await flushPromises();

        await textButton(wrapper, 'production.lots.hold').trigger('click');
        await flushPromises();

        // the Save/Discard/Cancel prompt is up, and the state action is still waiting behind it
        expect(wrapper.find('[data-testid="unsaved-dialog"]').exists()).toBe(
            true,
        );
        expect(wrapper.find('[data-testid="reason-dialog"]').exists()).toBe(
            false,
        );
        expect(post).not.toHaveBeenCalled();
        expect(put).not.toHaveBeenCalled();
        wrapper.unmount();
    });

    it('holds the lot straight away when nothing is unsaved', async () => {
        const wrapper = await mountLots();

        await textButton(wrapper, 'production.lots.hold').trigger('click');
        await flushPromises();

        // no guard prompt; the hold reason is asked for instead (the POST follows the answer)
        expect(wrapper.find('[data-testid="unsaved-dialog"]').exists()).toBe(
            false,
        );
        expect(wrapper.find('[data-testid="reason-dialog"]').exists()).toBe(
            true,
        );
        expect(post).not.toHaveBeenCalled();
        wrapper.unmount();
    });

    it('disables Save for a user who may write neither field', async () => {
        const wrapper = await mountLots({
            colour: false,
            remarks: false,
            hold: false,
            route: false,
            stage: false,
        });

        expect(toolbarButton(wrapper, 'save').attributes('disabled')).toBe('');
        expect(wrapper.text()).toContain('production.lots.read_only');
        wrapper.unmount();
    });

    it('keeps the stage read-only until the override is taken, and says it is derived', async () => {
        const wrapper = await mountLots();

        const stage = () => wrapper.get('[data-testid="stage-select"]');
        expect(stage().attributes('disabled')).toBe('');
        // a lot whose status nothing maps says so rather than looking like a lost figure
        expect(wrapper.text()).toContain('production.lots.stage_none');

        await textButton(wrapper, 'production.lots.stage_override').trigger(
            'click',
        );
        await flushPromises();

        expect(stage().attributes('disabled')).toBe(undefined);
        expect(wrapper.text()).toContain('production.lots.stage_override_hint');
        wrapper.unmount();
    });

    it('puts the pinned stage to the stage endpoint, and cancelling leaves the lot alone', async () => {
        const wrapper = await mountLots();

        await textButton(wrapper, 'production.lots.stage_override').trigger(
            'click',
        );
        await wrapper.get('[data-testid="stage-select"]').setValue('4');
        await flushPromises();

        await textButton(
            wrapper,
            'production.lots.stage_override_save',
        ).trigger('click');
        await flushPromises();

        expect(put).toHaveBeenCalledTimes(1);
        expect(put.mock.calls[0][0]).toBe('/production/lots/7/stage');

        // the editor stays open until the server answers; Cancel closes it and writes nothing
        put.mockClear();
        await textButton(
            wrapper,
            'production.lots.stage_override_cancel',
        ).trigger('click');
        await flushPromises();

        expect(put).not.toHaveBeenCalled();
        expect(
            wrapper.get('[data-testid="stage-select"]').attributes('disabled'),
        ).toBe('');
        wrapper.unmount();
    });

    it('shows who pinned an overridden stage and clears it through the guard', async () => {
        const wrapper = await mountLots(
            {
                colour: true,
                remarks: true,
                hold: true,
                route: true,
                stage: true,
            },
            [
                {
                    ...lot,
                    stage_id: 10,
                    stage: 'Exception / Hold',
                    stage_overridden: true,
                    stage_overridden_at: '07-10-2026 11:30',
                    stage_overridden_by: 'Asma (PPC)',
                },
            ],
        );

        // the badge and the note: a figure a human pinned must never read as a derived one
        expect(wrapper.text()).toContain('production.lots.stage_overridden');
        expect(wrapper.text()).toContain(
            'Pinned by Asma (PPC) on 07-10-2026 11:30',
        );
        expect(wrapper.text()).not.toContain('production.lots.stage_derived');

        await wrapper.get('#lot-remarks').setValue('something unsaved');
        await textButton(
            wrapper,
            'production.lots.stage_override_clear',
        ).trigger('click');
        await flushPromises();

        // unsaved edits first: the clear waits behind the Save/Discard prompt
        expect(wrapper.find('[data-testid="unsaved-dialog"]').exists()).toBe(
            true,
        );
        expect(del).not.toHaveBeenCalled();
        wrapper.unmount();
    });

    it('offers no stage override to a user without production.route.manage', async () => {
        const wrapper = await mountLots({
            colour: true,
            remarks: true,
            hold: true,
            route: false,
            stage: false,
        });

        expect(textButton(wrapper, 'production.lots.stage_override')).toBe(
            undefined,
        );
        expect(
            wrapper.get('[data-testid="stage-select"]').attributes('disabled'),
        ).toBe('');
        wrapper.unmount();
    });

    it('disables Save while query mode is open', async () => {
        const wrapper = await mountLots();
        expect(toolbarButton(wrapper, 'save').attributes('disabled')).toBe(
            undefined,
        );

        await toolbarButton(wrapper, 'enterQuery').trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('forms.query_mode');
        expect(toolbarButton(wrapper, 'save').attributes('disabled')).toBe('');
        wrapper.unmount();
    });
});

/*
 * The grid's two shapes. The flat one is the Oracle-shaped payload the mill names its own fields by
 * (`ProductionAnalyticsService::flatLots`); only the grid changes — the record form, the stage override and
 * every state action still read `lots`.
 */
describe('Lots screen — the flat, Oracle-shaped grid', () => {
    /** The last grid the page mounted. */
    const lastGrid = () => grids[grids.length - 1];

    it('shows the app’s own columns by default', async () => {
        const wrapper = await mountLots();

        expect(grids).toHaveLength(1);
        expect(lastGrid().rows).toHaveLength(1);
        // the app's own record, not the flat payload: the nested row names the party `party`, the flat one
        // `party_oracle_name`, so the two shapes are told apart by the columns only one of them has
        expect(lastGrid().columns.map((c) => c.field)).toContain('party');
        expect(lastGrid().columns.map((c) => c.field)).not.toContain(
            'party_oracle_name',
        );
        wrapper.unmount();
    });

    it('draws the flat payload under the mill’s own field names, in the report’s column order', async () => {
        const wrapper = await mountLots(undefined, [lot], {
            shape: 'flat',
            flatLots: [flatLot],
        });

        const columns = lastGrid().columns;
        expect(columns.map((c) => c.field)).toEqual([
            'lot_no_oracle',
            'po_no',
            'ref',
            'party_oracle_id',
            'party_oracle_name',
            'date_oracle',
            'design_oracle_id',
            'color_oracle_id',
            'color_oracle_name',
            'clr_status_oracle',
            'po_type_oracle',
            'quality',
            'gw_oracle',
            // the raw status beside the stage it resolves to — the stage never replaces the status
            'status_oracle',
            'stage_name',
            'greige_received_oracle',
            'greige_in_stock_oracle',
            'qty_issued',
            'wip',
            'finished',
            'dispatch',
        ]);
        // every header goes through t() in the `production.flat` group: no bare strings
        expect(columns.map((c) => c.headerName)).toEqual(
            columns.map((c) => `production.flat.${c.field}`),
        );
        // the keys the payload also carries are keys, not figures, so they are not columns
        expect(columns.map((c) => c.field)).not.toContain('lot_id');
        expect(columns.map((c) => c.field)).not.toContain('stage_id');
        wrapper.unmount();
    });

    it('keys the flat rows by the lot’s own id and leaves an unmeasured width blank', async () => {
        const wrapper = await mountLots(undefined, [lot], {
            shape: 'flat',
            flatLots: [flatLot],
        });

        // DataGrid keys its nodes by `id`; the payload's primary key is `lot_id`
        expect(lastGrid().rows).toEqual([{ ...flatLot, id: flatLot.lot_id }]);

        const format = (field: string) =>
            lastGrid().columns.find((c) => c.field === field)?.valueFormatter;
        // a null width is blank, not 0.00 — "not measured" and "zero" are different facts
        expect(format('gw_oracle')?.({ value: null })).toBe('');
        expect(format('gw_oracle')?.({ value: 63.5 })).toBe('63.50');
        expect(format('wip')?.({ value: 30 })).toBe('30.00');
        expect(format('wip')?.({ value: null })).toBe('');
        wrapper.unmount();
    });

    it('swaps shapes on the server, asking for the flat payload only when it will draw it', async () => {
        const wrapper = await mountLots();

        await wrapper.get('[data-testid="shape-flat"]').trigger('click');
        await flushPromises();

        expect(get).toHaveBeenCalledTimes(1);
        expect(get.mock.calls[0][0]).toBe('/production/lots');
        expect(get.mock.calls[0][1]).toEqual({ q: {}, shape: 'flat' });
        expect((get.mock.calls[0][2] as { only: string[] }).only).toContain(
            'flatLots',
        );
        wrapper.unmount();
    });

    it('does not ask for the flat payload when switching back to the app’s columns', async () => {
        const wrapper = await mountLots(undefined, [lot], {
            shape: 'flat',
            flatLots: [flatLot],
        });

        await wrapper.get('[data-testid="shape-nested"]').trigger('click');
        await flushPromises();

        expect(get.mock.calls[0][1]).toEqual({ q: {}, shape: 'nested' });
        expect((get.mock.calls[0][2] as { only: string[] }).only).not.toContain(
            'flatLots',
        );

        // asking for the shape already on screen is not a round trip at all
        get.mockClear();
        await wrapper.get('[data-testid="shape-flat"]').trigger('click');
        await flushPromises();
        expect(get).not.toHaveBeenCalled();
        wrapper.unmount();
    });

    it('sends the shape swap through the unsaved guard', async () => {
        const wrapper = await mountLots();

        await wrapper.get('#lot-remarks').setValue('rush this lot');
        await flushPromises();

        await wrapper.get('[data-testid="shape-flat"]').trigger('click');
        await flushPromises();

        // the Save/Discard/Cancel prompt is up and nothing has been fetched yet
        expect(wrapper.find('[data-testid="unsaved-dialog"]').exists()).toBe(
            true,
        );
        expect(get).not.toHaveBeenCalled();
        wrapper.unmount();
    });
});
