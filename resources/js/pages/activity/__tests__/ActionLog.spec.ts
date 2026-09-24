import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';

/*
 * The log is server-paginated on purpose — the table only grows. What a mounted page has to prove is that it
 * never tries to do the work itself: every filter and every page step is a fresh request to the same route,
 * carrying exactly the filters that are set, and the paging controls stop at the ends.
 */
const get = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ name: 'Head', setup: () => () => null }),
    router: { get: (...args: unknown[]) => get(...args), on: () => () => {} },
}));

vi.mock('@/components/forms/DataGrid.vue', () => ({
    default: defineComponent({
        name: 'DataGrid',
        props: { rows: { type: Array, default: () => [] } },
        setup: (props) => () =>
            h('div', { 'data-testid': 'grid' }, String(props.rows.length)),
    }),
}));

vi.mock('@/components/activity/DeviceNamePrompt.vue', () => ({
    default: defineComponent({
        name: 'DeviceNamePrompt',
        setup: () => () => null,
    }),
}));

const row = {
    id: 1,
    action: 'lot.closed',
    label: 'Closed lot 640101',
    description: 'Closed lot 640101',
    user: 'Asif Mehmood',
    subject_type: 'App\\Modules\\Production\\Models\\Lot',
    subject_id: 7,
    ip_address: '10.20.30.40',
    device_id: '01JB0000000000000000000001',
    computer_name: 'PPC-OFFICE-01',
    occurred_at: '2026-09-24T09:15:00+05:00',
};

async function mountLog(
    meta: Partial<{
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
    }> = {},
    filters: Record<string, unknown> = {},
) {
    const { default: ActionLog } =
        await import('@/pages/activity/ActionLog.vue');
    const wrapper = mount(ActionLog, {
        attachTo: document.body,
        props: {
            data: [row],
            meta: {
                current_page: 1,
                last_page: 3,
                per_page: 50,
                total: 113,
                from: 1,
                to: 50,
                ...meta,
            },
            filters: {
                user_id: null,
                action: null,
                device_id: null,
                from: null,
                to: null,
                ...filters,
            },
            options: {
                users: [{ value: 4, label: 'Asif Mehmood' }],
                actions: [{ value: 'lot.closed', label: 'Lot closed' }],
                devices: [
                    {
                        value: '01JB0000000000000000000001',
                        label: 'PPC-OFFICE-01',
                    },
                ],
            },
        },
    });
    await flushPromises();
    return wrapper;
}

const button = (wrapper: Awaited<ReturnType<typeof mountLog>>, test: string) =>
    wrapper.get(`[data-test="${test}"]`);

beforeEach(() => get.mockClear());
afterEach(() => {
    document.body.innerHTML = '';
});

describe('ActionLog screen', () => {
    it('re-requests the route when a filter changes, sending only what is set', async () => {
        const wrapper = await mountLog();

        await wrapper.get('#filter-action').setValue('lot.closed');
        await flushPromises();

        expect(get).toHaveBeenCalledTimes(1);
        expect(get.mock.calls[0][0]).toBe('/activity/actions');
        expect(get.mock.calls[0][1]).toEqual({ action: 'lot.closed' });
        wrapper.unmount();
    });

    it('carries every set filter together, and drops them all on clear', async () => {
        const wrapper = await mountLog();

        await wrapper.get('#filter-user').setValue('4');
        await wrapper.get('#filter-from').setValue('2026-09-01');
        await flushPromises();

        expect(get.mock.calls.at(-1)?.[1]).toEqual({
            user_id: '4',
            from: '2026-09-01',
        });

        await button(wrapper, 'reset-filters').trigger('click');
        await flushPromises();

        expect(get.mock.calls.at(-1)?.[1]).toEqual({});
        wrapper.unmount();
    });

    it('steps pages on the server and keeps the filters while doing it', async () => {
        const wrapper = await mountLog({ current_page: 2 });

        await wrapper.get('#filter-action').setValue('lot.closed');
        await flushPromises();
        get.mockClear();

        await button(wrapper, 'next-page').trigger('click');
        await flushPromises();

        expect(get.mock.calls[0][1]).toEqual({ action: 'lot.closed', page: 3 });
        wrapper.unmount();
    });

    it('stops at both ends', async () => {
        const first = await mountLog({ current_page: 1, last_page: 3 });
        expect(
            (button(first, 'previous-page').element as HTMLButtonElement)
                .disabled,
        ).toBe(true);
        expect(
            (button(first, 'next-page').element as HTMLButtonElement).disabled,
        ).toBe(false);
        first.unmount();

        const last = await mountLog({ current_page: 3, last_page: 3 });
        expect(
            (button(last, 'next-page').element as HTMLButtonElement).disabled,
        ).toBe(true);
        last.unmount();
    });
});
