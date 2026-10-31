import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import type {
    SourceColumn,
    SourceConnection,
    SourceError,
    SourceObject,
    SourceSelection,
} from '@/types/oracle';

/*
 * The Oracle source schema browser.
 *
 * Two promises are load-bearing here and both are asserted below, because this is the only screen in the
 * app that reads the client's live production ERP:
 *
 *  - it says so, unmissably, on every render; and
 *  - Save, New record and Delete record are disabled — visibly, not hidden — so nobody can mistake the
 *    screen for one that writes.
 *
 * The rest is the Oracle-Forms contract the other screens keep: criteria are read-only until F7, F8 runs
 * them against the server (never a filter applied in the browser), and the pager stops at both ends.
 *
 * The real Wayfinder route helpers are used, not stubs: the URLs this screen navigates to are part of
 * what is being tested.
 */

const visit = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ name: 'Head', setup: () => () => null }),
    router: {
        visit: (...args: unknown[]) => visit(...args),
        // useUnsavedGuard subscribes to navigations; nothing on this screen can ever be dirty, so the
        // unsubscriber is all it needs back.
        on: () => () => {},
    },
}));

/** A grid stub that lists each row's name as a button, and reports the selection it was handed. */
vi.mock('@/components/forms/DataGrid.vue', () => ({
    default: defineComponent({
        name: 'DataGrid',
        props: {
            rows: { type: Array, default: () => [] },
            selectedId: { type: [Number, String], default: null },
        },
        emits: ['rowSelected', 'rowActivated'],
        setup:
            (props, { emit }) =>
            () =>
                h(
                    'div',
                    { 'data-selected': String(props.selectedId ?? '') },
                    (
                        props.rows as { id: string | number; name?: string }[]
                    ).map((row) =>
                        h(
                            'button',
                            {
                                'data-testid': `row-${row.id}`,
                                onClick: () => emit('rowSelected', row),
                            },
                            row.name ?? '',
                        ),
                    ),
                ),
    }),
}));

const connection: SourceConnection = {
    driver: 'oracle',
    mocked: false,
    username: 'NASIRPKG',
    host: '10.0.0.9',
    port: '1521',
    service: 'NASIRPKG',
    schema_prefix: '',
    server_version: '11g',
};

const objects: SourceObject[] = [
    {
        owner: 'NASIRPKG',
        name: 'CORRUGATION_PLAN',
        type: 'TABLE',
        columns: 44,
        rows: 0,
        analysed_at: '2026-10-22',
    },
    {
        owner: 'NASIRPKG',
        name: 'REELS',
        type: 'VIEW',
        columns: 2,
        rows: null,
        analysed_at: null,
    },
];

const columns: SourceColumn[] = [
    {
        position: 1,
        name: 'PP_MAIN_ID',
        type: 'NUMBER(8)',
        nullable: true,
        default: null,
    },
    {
        position: 2,
        name: 'ORDER_TYPE',
        type: 'VARCHAR2(10)',
        nullable: true,
        default: "'N'",
    },
];

type Overrides = {
    connection?: Partial<SourceConnection>;
    filters?: Partial<{
        q: string | null;
        owner: string | null;
        type: 'TABLE' | 'VIEW' | null;
        page: number;
    }>;
    page?: Partial<{ total: number; page: number; pages: number }>;
    selected?: SourceSelection | null;
    error?: SourceError | null;
};

async function render(overrides: Overrides = {}) {
    const { default: Page } = await import('@/pages/oracle/SourceBrowser.vue');

    const wrapper = mount(Page, {
        attachTo: document.body,
        props: {
            connection: { ...connection, ...overrides.connection },
            filters: {
                q: null,
                owner: 'NASIRPKG',
                type: null,
                page: 1,
                ...overrides.filters,
            },
            defaultOwner: 'NASIRPKG',
            owners: [
                { owner: 'NASIRPKG', objects: 195 },
                { owner: 'SYS', objects: 3635 },
            ],
            types: ['TABLE', 'VIEW'] as const,
            objects: {
                rows: objects,
                total: 195,
                page: 1,
                per_page: 50,
                pages: 4,
                ...overrides.page,
            },
            selected: overrides.selected ?? null,
            error: overrides.error ?? null,
        },
    });
    await flushPromises();

    return wrapper;
}

const byLabel = (wrapper: Awaited<ReturnType<typeof render>>, label: string) =>
    wrapper.findAll('button').find((b) => b.attributes('aria-label') === label);

const lastUrl = (): string => String(visit.mock.calls.at(-1)?.[0] ?? '');

beforeEach(() => {
    visit.mockClear();
});

describe('Oracle source browser', () => {
    it('says it is read-only and keeps the record-changing actions off', async () => {
        const wrapper = await render();

        expect(wrapper.find('[data-testid="read-only-alert"]').exists()).toBe(
            true,
        );
        expect(wrapper.find('[data-testid="read-only-badge"]').text()).toBe(
            'oracle.source.read_only',
        );
        expect(wrapper.text()).toContain('oracle.source.live_warning');

        for (const action of [
            'forms.actions.save',
            'forms.actions.newRecord',
            'forms.actions.deleteRecord',
        ]) {
            expect(byLabel(wrapper, action)?.attributes('disabled')).toBe('');
        }

        wrapper.unmount();
    });

    it('names the stand-in instead when the connection is not the client ERP', async () => {
        const wrapper = await render({ connection: { mocked: true } });

        expect(wrapper.text()).toContain('oracle.source.standin_warning');
        expect(wrapper.text()).not.toContain('oracle.source.live_warning');

        wrapper.unmount();
    });

    it('keeps the criteria read-only until query mode, then runs them on the server', async () => {
        const wrapper = await render();

        expect(wrapper.get('#source-q').attributes('disabled')).toBeDefined();
        expect(
            wrapper.get('#source-owner').attributes('disabled'),
        ).toBeDefined();
        expect(wrapper.find('[data-testid="run-query"]').exists()).toBe(false);

        await wrapper.get('[data-testid="enter-query"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('#source-q').attributes('disabled')).toBeUndefined();
        expect(visit).not.toHaveBeenCalled();

        await wrapper.get('#source-q').setValue('reel');
        await wrapper.get('#source-owner').setValue('SYS');
        await wrapper.get('#source-type').setValue('VIEW');
        await wrapper.get('[data-testid="run-query"]').trigger('click');
        await flushPromises();

        expect(visit).toHaveBeenCalledTimes(1);
        expect(lastUrl()).toContain('/oracle/source');
        expect(lastUrl()).toContain('q=reel');
        expect(lastUrl()).toContain('owner=SYS');
        expect(lastUrl()).toContain('type=VIEW');

        wrapper.unmount();
    });

    it('asks the server for one object when a row is picked', async () => {
        const wrapper = await render();

        await wrapper
            .get('[data-testid="row-NASIRPKG.CORRUGATION_PLAN"]')
            .trigger('click');
        await flushPromises();

        expect(lastUrl()).toContain('/oracle/source/NASIRPKG/CORRUGATION_PLAN');
        // The applied criteria travel with it, so the list is unchanged behind the detail.
        expect(lastUrl()).toContain('owner=NASIRPKG');

        wrapper.unmount();
    });

    it('shows the selected object’s columns beside the list', async () => {
        const wrapper = await render({
            selected: {
                owner: 'NASIRPKG',
                name: 'CORRUGATION_PLAN',
                type: 'TABLE',
                columns,
            },
        });

        expect(wrapper.find('[data-testid="columns-block"]').exists()).toBe(
            true,
        );
        expect(wrapper.text()).toContain('PP_MAIN_ID');
        expect(wrapper.text()).toContain('ORDER_TYPE');
        // The objects list is still there: this is master/detail, not a second screen.
        expect(wrapper.text()).toContain('CORRUGATION_PLAN');
        expect(wrapper.text()).toContain('oracle.source.columns_title');

        wrapper.unmount();
    });

    it('says the row counts are statistics, not counts', async () => {
        const wrapper = await render();

        expect(wrapper.text()).toContain('oracle.source.rows_note');

        wrapper.unmount();
    });

    it('stops the pager at both ends and pages through the server', async () => {
        const first = await render();
        expect(
            first.get('[data-testid="prev-page"]').attributes('disabled'),
        ).toBe('');

        await first.get('[data-testid="next-page"]').trigger('click');
        await flushPromises();
        expect(lastUrl()).toContain('page=2');
        first.unmount();

        visit.mockClear();
        const last = await render({
            filters: { page: 4 },
            page: { page: 4, pages: 4 },
        });
        expect(
            last.get('[data-testid="next-page"]').attributes('disabled'),
        ).toBe('');
        expect(
            last.get('[data-testid="prev-page"]').attributes('disabled'),
        ).toBeUndefined();
        last.unmount();
    });

    it('renders a failure as a message, with no object list', async () => {
        const wrapper = await render({
            error: {
                message: 'oracle.source.errors.unreachable',
                detail: 'ORA-12541: TNS:no listener',
                code: 'ORA-12541',
                hints: ['check the host and port'],
            },
            page: { total: 0, pages: 1 },
        });

        expect(wrapper.text()).toContain('ORA-12541: TNS:no listener');
        expect(wrapper.text()).toContain('check the host and port');
        expect(wrapper.text()).not.toContain('oracle.source.rows_note');
        // Query execution is pointless while the source is unreadable.
        expect(
            byLabel(wrapper, 'forms.actions.executeQuery')?.attributes(
                'disabled',
            ),
        ).toBe('');

        wrapper.unmount();
    });
});
