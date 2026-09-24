import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';
import type { LotScreenRow } from '@/types/production';

/*
 * The Lots screen's two Oracle-Forms guarantees that only a mounted page can show: a state action (Hold /
 * Release / Cancel) is a POST, which the unsaved guard's router hook cannot intercept, so it must go through
 * `guard.guard()` itself; and Save is off for anyone who may not write, and while query mode is open.
 */

type Edit = { colour_status: string | null; remarks: string | null };

const put = vi.fn();
const post = vi.fn();
const get = vi.fn();
const visit = vi.fn();

/** Enough of Inertia's `useForm` for this page: the two fields, `isDirty` against the current defaults. */
function makeForm(initial: Edit) {
    let defaults: Edit = { ...initial };
    const form = reactive({
        colour_status: initial.colour_status,
        remarks: initial.remarks,
        errors: {} as Record<string, string>,
        get isDirty(): boolean {
            return (
                form.colour_status !== defaults.colour_status ||
                form.remarks !== defaults.remarks
            );
        },
        defaults(next: Edit): void {
            defaults = { ...next };
        },
        reset(): void {
            form.colour_status = defaults.colour_status;
            form.remarks = defaults.remarks;
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
        visit: (...args: unknown[]) => visit(...args),
        on: () => () => {},
    },
    useForm: (initial: Edit) => makeForm(initial),
}));

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
    can = { colour: true, remarks: true, hold: true, route: true },
) {
    const { default: Lots } = await import('@/pages/production/Lots.vue');
    const wrapper = mount(Lots, {
        attachTo: document.body,
        props: {
            lots: [lot],
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
    put.mockClear();
    post.mockClear();
    get.mockClear();
    visit.mockClear();
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
        });

        expect(toolbarButton(wrapper, 'save').attributes('disabled')).toBe('');
        expect(wrapper.text()).toContain('production.lots.read_only');
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
