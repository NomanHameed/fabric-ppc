import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';
import { setTranslations } from '@/lib/i18n';
import type { LotScreenRow } from '@/types/production';

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
vi.mock('@/components/stages/StageProcessSelect.vue', () =>
    selectStub('StageProcessSelect', 'stage-process-select'),
);

vi.mock('@/components/forms/DataGrid.vue', () => ({
    default: defineComponent({
        name: 'DataGrid',
        props: { rows: { type: Array, default: () => [] } },
        setup: () => () => null,
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
    lot_no: '264899',
    contract_id: 3,
    po_no: 261071,
    party: 'NORMAL PARTY',
    ref_no: 'REF-1',
    po_date: '17-08-2026',
    quality: '88X60/30X30 100% VISCOSE',
    gsm: null,
    design: 'E1143/119',
    colour: '520 PLUM',
    colour_status: 'DIP',
    g_width: null,
    f_width: null,
    po_type: 'DYED',
    state: 'open',
    hold_reason: null,
    cancel_reason: null,
    state_changed_at: null,
    current_label: null,
    process_flag: 'pending',
    stage_id: null,
    stage: null,
    stage_process_id: null,
    stage_process: null,
    stage_overridden: false,
    stage_overridden_at: null,
    stage_overridden_by: null,
    greige_qty: 300,
    stock_qty: 300,
    issued_qty: 0,
    wip_qty: 0,
    finished_qty: 0,
    dispatched_qty: 0,
    loss_qty: 0,
    last_movement_at: null,
    oracle_missing_at: null,
    remarks: null,
};

async function mountLots(
    can = { colour: true, remarks: true, hold: true, route: true, stage: true },
    rows: LotScreenRow[] = [lot],
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
        const process = () =>
            wrapper.get('[data-testid="stage-process-select"]');
        expect(stage().attributes('disabled')).toBe('');
        expect(process().attributes('disabled')).toBe('');
        // a lot whose status nothing maps says so rather than looking like a lost figure
        expect(wrapper.text()).toContain('production.lots.stage_none');

        await textButton(wrapper, 'production.lots.stage_override').trigger(
            'click',
        );
        await flushPromises();

        expect(stage().attributes('disabled')).toBe(undefined);
        expect(process().attributes('disabled')).toBe(undefined);
        expect(wrapper.text()).toContain('production.lots.stage_override_hint');
        wrapper.unmount();
    });

    it('puts the pinned pair to the stage endpoint, and cancelling leaves the lot alone', async () => {
        const wrapper = await mountLots();

        await textButton(wrapper, 'production.lots.stage_override').trigger(
            'click',
        );
        await wrapper.get('[data-testid="stage-select"]').setValue('4');
        await wrapper
            .get('[data-testid="stage-process-select"]')
            .setValue('41');
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
                    stage_process_id: 101,
                    stage_process: 'Hold',
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
