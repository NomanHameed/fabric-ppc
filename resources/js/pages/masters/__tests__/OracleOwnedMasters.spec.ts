import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';
import { statusLine } from '@/lib/statusLine';
import type { MasterField, MasterMeta, MasterRow } from '@/types/masters';

/*
 * Oracle-owned masters, from the screen's side (App\Modules\Foundation\Masters\OracleOwned decides which).
 * What only a mounted page can show:
 *
 *  - the write actions are *disabled*, not hidden: Save / New / Delete are still there, greyed out, so the
 *    operator can see that the screen simply does not write — and the status line says Oracle owns the data;
 *  - query mode, F7/F8 and browsing are untouched, which is the whole point of keeping the screen;
 *  - a partially-owned master (parties) keeps Save for the fields the mill owns — the local aliases above
 *    all, which the unresolved-party flow depends on — while Oracle's own code and name are read-only;
 *  - an app-owned master is unaffected.
 */

type Fields = Record<string, unknown>;

const put = vi.fn();
const post = vi.fn();
const get = vi.fn();
const del = vi.fn();
const visit = vi.fn();

/** Enough of Inertia's `useForm` for these pages. */
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
        transform(): typeof form {
            return form;
        },
        post,
        put,
    });
    return form;
}

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
    router: {
        get: (...args: unknown[]) => get(...args),
        delete: (...args: unknown[]) => del(...args),
        visit: (...args: unknown[]) => visit(...args),
        on: () => () => {},
    },
    useForm: (initial: Fields) => makeForm(initial),
}));

vi.mock('@/components/forms/DataGrid.vue', () => ({
    default: defineComponent({
        name: 'DataGrid',
        props: {
            rows: { type: Array, default: () => [] },
            columns: { type: Array, default: () => [] },
            selectedId: { type: [Number, String], default: null },
        },
        setup: () => () => null,
    }),
}));

/** The alias detail grid: its one column's `editable` flag is what the party lock has to leave alone. */
const aliasGrids: { editable: boolean }[] = [];

vi.mock('@/components/forms/DetailGrid.vue', () => ({
    default: defineComponent({
        name: 'DetailGrid',
        props: {
            rows: { type: Array, default: () => [] },
            columns: { type: Array, default: () => [] },
            newRow: { type: Function, default: undefined },
        },
        setup: (props) => {
            aliasGrids.push(
                (props.columns as { editable: boolean }[])[0] ?? {
                    editable: false,
                },
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

const field = (
    name: string,
    overrides: Partial<MasterField> = {},
): MasterField => ({
    name,
    type: 'text',
    label: `masters.fields.${name}`,
    required: false,
    options: [],
    lov: null,
    displayFrom: null,
    span: 3,
    grid: true,
    queryable: true,
    readonly: false,
    ...overrides,
});

/**
 * A master Oracle owns outright — nothing on the row is the app's. No real master is shaped this way today
 * (qualities keeps `is_active`, see `qualitiesMeta` below), but `machines` and `operations` will land here
 * once a sync exists, so the shape is worth holding onto.
 */
const qualities: MasterMeta = {
    key: 'qualities',
    title: 'Qualities',
    canEdit: true,
    oracleOwned: true,
    appOwnedFields: [],
    fields: [
        field('oracle_code', { type: 'number' }),
        field('description', { required: true }),
        field('is_active', { type: 'bool' }),
    ],
};

/**
 * The qualities screen as it actually ships: Oracle owns the code, the description and everything parsed out
 * of it, while `is_active` stays the mill's — the import never writes it and it only hides a quality from the
 * LOVs, which matters because 672 of the live instance's 3,035 qualities have no description at all.
 */
const qualitiesMeta: MasterMeta = { ...qualities, appOwnedFields: ['is_active'] };

const qualityRows: MasterRow[] = [
    {
        id: 1,
        oracle_code: 5150,
        description: '88X60/30X30 100% VISCOSE',
        is_active: true,
    },
];

/** An app-owned master, for contrast. */
const departments: MasterMeta = {
    ...qualities,
    key: 'departments',
    title: 'Departments',
    oracleOwned: false,
    appOwnedFields: [],
    fields: [field('code'), field('name_en'), field('is_active', { type: 'bool' })],
};

async function mountMaster(resource: MasterMeta, rows: MasterRow[]) {
    const { default: Page } = await import('@/pages/masters/MasterForm.vue');
    return mount(Page, {
        attachTo: document.body,
        props: { resource, rows, criteria: {} },
    });
}

async function mountParties(
    overrides: Partial<{ oracleOwned: boolean; appOwnedFields: string[] }> = {},
) {
    const { default: Page } = await import('@/pages/masters/Parties.vue');
    return mount(Page, {
        attachTo: document.body,
        props: {
            parties: [
                {
                    id: 1,
                    oracle_code: 7731,
                    name: 'ALKARAM TEXTILE',
                    type: 'brand',
                    is_active: true,
                    aliases: [{ id: 3, alias: 'AK' }],
                },
            ],
            canEdit: true,
            criteria: {},
            oracleOwned: true,
            appOwnedFields: ['type', 'is_active', 'aliases'],
            ...overrides,
        },
    });
}

type Wrapper = ReturnType<typeof mount>;

const action = (wrapper: Wrapper, name: string) =>
    wrapper.get(`[aria-label="forms.actions.${name}"]`);
const isOff = (wrapper: Wrapper, name: string) =>
    action(wrapper, name).attributes('disabled') === '';

beforeEach(() => {
    put.mockClear();
    post.mockClear();
    get.mockClear();
    del.mockClear();
    visit.mockClear();
    aliasGrids.length = 0;
    statusLine.reset();
});

describe('a master Oracle owns outright', () => {
    it('greys out Save, New and Delete and says on the status line who owns the data', async () => {
        const wrapper = await mountMaster(qualities, qualityRows);

        expect(isOff(wrapper, 'save')).toBe(true);
        expect(isOff(wrapper, 'newRecord')).toBe(true);
        expect(isOff(wrapper, 'deleteRecord')).toBe(true);
        // still there to be seen, not removed
        expect(
            wrapper.findAll('[aria-label="forms.actions.save"]'),
        ).toHaveLength(1);
        expect(
            wrapper.findAll('[aria-label="forms.actions.deleteRecord"]'),
        ).toHaveLength(1);
        expect(statusLine.state.message).toBe('masters.oracle_owned.note');

        // and every field reads as output only
        expect(
            wrapper.get('#f-oracle_code').attributes('disabled'),
        ).toBe('');
        expect(wrapper.get('#f-description').attributes('disabled')).toBe('');
        expect(wrapper.get('#f-is_active').attributes('disabled')).toBe('');

        wrapper.unmount();
    });

    it('lets the qualities screen save only the active flag, Oracle fields still locked', async () => {
        const wrapper = await mountMaster(qualitiesMeta, qualityRows);

        // Save is live for the one field the mill owns; creating and deleting a quality stay Oracle's.
        expect(isOff(wrapper, 'save')).toBe(false);
        expect(isOff(wrapper, 'newRecord')).toBe(true);
        expect(isOff(wrapper, 'deleteRecord')).toBe(true);
        expect(statusLine.state.message).toBe(
            'masters.oracle_owned.note_partial',
        );

        expect(wrapper.get('#f-is_active').attributes('disabled')).toBeUndefined();
        expect(wrapper.get('#f-oracle_code').attributes('disabled')).toBe('');
        expect(wrapper.get('#f-description').attributes('disabled')).toBe('');

        wrapper.unmount();
    });

    it('keeps browsing, query mode and record navigation working', async () => {
        const wrapper = await mountMaster(qualities, qualityRows);

        expect(isOff(wrapper, 'enterQuery')).toBe(false);
        expect(isOff(wrapper, 'executeQuery')).toBe(false);
        expect(statusLine.state.recordIndex).toBe(1);
        expect(statusLine.state.recordCount).toBe(1);

        await action(wrapper, 'enterQuery').trigger('click');
        // the criteria field is enterable even though the record's is not
        const criteria = wrapper.get('#f-description');
        expect(criteria.attributes('disabled')).toBeUndefined();
        await criteria.setValue('%VISCOSE%');

        await action(wrapper, 'executeQuery').trigger('click');
        expect(get).toHaveBeenCalledTimes(1);
        expect(get.mock.calls[0][0]).toBe('/masters/qualities');
        expect(get.mock.calls[0][1]).toEqual({
            q: { description: '%VISCOSE%' },
        });

        wrapper.unmount();
    });

    it('never posts, even if the disabled Save is clicked', async () => {
        const wrapper = await mountMaster(qualities, qualityRows);

        await action(wrapper, 'save').trigger('click');
        await action(wrapper, 'newRecord').trigger('click');
        await action(wrapper, 'deleteRecord').trigger('click');

        expect(post).not.toHaveBeenCalled();
        expect(put).not.toHaveBeenCalled();
        expect(del).not.toHaveBeenCalled();
        wrapper.unmount();
    });
});

describe('an app-owned master (departments)', () => {
    it('keeps every write action and says nothing about Oracle', async () => {
        const wrapper = await mountMaster(departments, [
            { id: 1, code: 'DRYING', name_en: 'Drying', is_active: true },
        ]);

        expect(isOff(wrapper, 'save')).toBe(false);
        expect(isOff(wrapper, 'newRecord')).toBe(false);
        expect(isOff(wrapper, 'deleteRecord')).toBe(false);
        expect(wrapper.get('#f-code').attributes('disabled')).toBeUndefined();
        expect(statusLine.state.message).toBe('');

        wrapper.unmount();
    });
});

describe('the parties screen, where Oracle owns only the identity', () => {
    it('locks the Oracle code and name but keeps the fields the mill owns', async () => {
        const wrapper = await mountParties();

        expect(wrapper.get('#f-oracle_code').attributes('disabled')).toBe('');
        expect(wrapper.get('#f-name').attributes('disabled')).toBe('');
        expect(wrapper.get('#f-type').attributes('disabled')).toBeUndefined();
        expect(
            wrapper.get('#f-is_active').attributes('disabled'),
        ).toBeUndefined();
        // the alias grid stays editable: local shorthand is the app's mapping, not Oracle's data
        expect(aliasGrids[0].editable).toBe(true);

        wrapper.unmount();
    });

    it('greys out New and Delete, keeps Save, and notes the partial lock', async () => {
        const wrapper = await mountParties();

        expect(isOff(wrapper, 'newRecord')).toBe(true);
        expect(isOff(wrapper, 'deleteRecord')).toBe(true);
        expect(isOff(wrapper, 'save')).toBe(false);
        expect(statusLine.state.message).toBe(
            'masters.oracle_owned.note_partial',
        );

        await action(wrapper, 'save').trigger('click');
        expect(put).toHaveBeenCalledTimes(1);
        expect(put.mock.calls[0][0]).toBe('/masters/parties/1');
        expect(del).not.toHaveBeenCalled();

        wrapper.unmount();
    });

    it('is a plain editable screen again the moment the lock is lifted', async () => {
        const wrapper = await mountParties({
            oracleOwned: false,
            appOwnedFields: [],
        });

        expect(isOff(wrapper, 'newRecord')).toBe(false);
        expect(isOff(wrapper, 'deleteRecord')).toBe(false);
        expect(
            wrapper.get('#f-name').attributes('disabled'),
        ).toBeUndefined();
        expect(statusLine.state.message).toBe('');

        wrapper.unmount();
    });
});
