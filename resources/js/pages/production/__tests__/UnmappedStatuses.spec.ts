import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';
import type { UnmappedStatusRow } from '@/types/production';

/*
 * The Unmapped Statuses report's one action: picking a row opens the Stage / Stage Process pair, the process
 * list follows the chosen stage, and nothing is posted until the operator confirms — adding an alias
 * re-classifies historical production, so it must never happen on a stray click.
 */

type Fields = Record<string, unknown>;

const post = vi.fn();
const visit = vi.fn();
let selectedId: number | string | null | undefined;

function makeForm(initial: Fields) {
    const form = reactive({
        ...initial,
        errors: {} as Record<string, string>,
        processing: false,
        clearErrors(): void {
            form.errors = {};
        },
        post,
    });
    return form;
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
    router: { visit: (...args: unknown[]) => visit(...args) },
    useForm: (initial: Fields) => makeForm(initial),
}));

/** A grid stub that reports the selection it was given and can select a row on demand. */
vi.mock('@/components/forms/DataGrid.vue', () => ({
    default: defineComponent({
        name: 'DataGrid',
        props: {
            rows: { type: Array, default: () => [] },
            selectedId: { type: [Number, String], default: null },
        },
        emits: ['rowSelected', 'rowActivated'],
        setup: (props, { emit }) => {
            selectedId = props.selectedId as number | null;
            return () =>
                h(
                    'div',
                    (props.rows as UnmappedStatusRow[]).map((row) =>
                        h('button', {
                            'data-testid': `row-${row.id}`,
                            onClick: () => {
                                selectedId = row.id;
                                emit('rowSelected', row);
                            },
                        }),
                    ),
                );
        },
    }),
}));

vi.mock('@/components/stages/StageSelect.vue', () => ({
    default: defineComponent({
        name: 'StageSelect',
        props: { modelValue: { type: Number, default: null } },
        emits: ['update:modelValue'],
        setup:
            (_props, { emit }) =>
            () =>
                h(
                    'select',
                    {
                        'data-testid': 'stage-select',
                        onChange: (e: Event) =>
                            emit(
                                'update:modelValue',
                                Number((e.target as HTMLSelectElement).value),
                            ),
                    },
                    [4, 5].map((id) =>
                        h('option', { value: String(id) }, `Stage ${id}`),
                    ),
                ),
    }),
}));

const row: UnmappedStatusRow = {
    id: 12,
    label: 'WEIRD ONE/',
    normalised: 'WEIRD ONE',
    lots: 3,
    lot_id: 12,
};

async function mountReport(rows: UnmappedStatusRow[] = [row]) {
    const { default: Page } =
        await import('@/pages/production/UnmappedStatuses.vue');
    return mount(Page, {
        attachTo: document.body,
        props: {
            statuses: rows,
            statusesTotal: rows.length,
            lotsAffected: rows.reduce((n, r) => n + r.lots, 0),
            lotsWithoutStage: 4,
        },
    });
}

const textButton = (wrapper: ReturnType<typeof mount>, label: string) =>
    wrapper.findAll('button').find((b) => b.text() === label);

beforeEach(() => {
    post.mockClear();
    visit.mockClear();
    selectedId = undefined;
});

describe('Unmapped Statuses report', () => {
    it('offers no mapping block until a row is picked', async () => {
        const wrapper = await mountReport();

        expect(wrapper.find('[data-testid="stage-select"]').exists()).toBe(
            false,
        );
        expect(wrapper.text()).toContain('production.unmapped.map_pick');

        await wrapper.get('[data-testid="row-12"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-testid="stage-select"]').exists()).toBe(
            true,
        );
        expect(selectedId).toBe(12);
        wrapper.unmount();
    });

    it('posts nothing until a stage is chosen and the mapping confirmed', async () => {
        const wrapper = await mountReport();
        await wrapper.get('[data-testid="row-12"]').trigger('click');
        await flushPromises();

        // confirm is off with no stage chosen, and clicking it posts nothing
        const confirm = textButton(wrapper, 'production.unmapped.map_confirm');
        expect(confirm?.attributes('disabled')).toBe('');
        await confirm?.trigger('click');
        expect(post).not.toHaveBeenCalled();

        await wrapper.get('[data-testid="stage-select"]').setValue('4');
        await flushPromises();

        await textButton(wrapper, 'production.unmapped.map_confirm')?.trigger(
            'click',
        );
        await flushPromises();

        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][0]).toBe(
            '/production/unmapped-statuses/4/aliases',
        );
        wrapper.unmount();
    });

    it('closes the block on cancel without posting', async () => {
        const wrapper = await mountReport();
        await wrapper.get('[data-testid="row-12"]').trigger('click');
        await wrapper.get('[data-testid="stage-select"]').setValue('4');
        await flushPromises();

        await textButton(wrapper, 'production.unmapped.map_cancel')?.trigger(
            'click',
        );
        await flushPromises();

        expect(post).not.toHaveBeenCalled();
        expect(wrapper.find('[data-testid="stage-select"]').exists()).toBe(
            false,
        );
        wrapper.unmount();
    });

    it('says so, and offers the master, when nothing is unmapped', async () => {
        const wrapper = await mountReport([]);

        expect(wrapper.text()).toContain('production.unmapped.none');
        await textButton(wrapper, 'production.unmapped.open_master')?.trigger(
            'click',
        );
        expect(visit).toHaveBeenCalledWith('/masters/stages');
        wrapper.unmount();
    });
});
