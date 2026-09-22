import type { GridApi } from 'ag-grid-community';
import { AgGridVue } from 'ag-grid-vue3';
import { flushPromises, mount } from '@vue/test-utils';
import { beforeAll, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick } from 'vue';
import DataGrid from '@/components/forms/DataGrid.vue';
import { registerAgGrid } from '@/lib/agGrid';

type Row = { id: number | string; name: string; qty: number };

beforeAll(() => {
    registerAgGrid();
    globalThis.ResizeObserver ??= class {
        observe(): void {}
        unobserve(): void {}
        disconnect(): void {}
    } as unknown as typeof ResizeObserver;
});

async function mountGrid(
    listeners: Record<string, (...args: unknown[]) => void>,
) {
    const Host = defineComponent({
        setup() {
            return () =>
                h(DataGrid<Row>, {
                    rows: [
                        { id: 1, name: 'a', qty: 10 },
                        { id: 2, name: 'b', qty: 20 },
                    ],
                    columns: [{ field: 'name' }, { field: 'qty' }],
                    pinnedBottomRows: [{ id: 'total', name: 'Total', qty: 30 }],
                    ...listeners,
                });
        },
    });
    const wrapper = mount(Host, { attachTo: document.body });
    await flushPromises();
    await nextTick();
    const api = (
        wrapper.findComponent(AgGridVue).vm as unknown as { api: GridApi<Row> }
    ).api;
    return { wrapper, api };
}

describe('DataGrid', () => {
    it('emits rowSelected on a click and rowActivated on a double-click or Enter, never for the pinned totals row', async () => {
        const selected = vi.fn();
        const activated = vi.fn();
        const { wrapper, api } = await mountGrid({
            onRowSelected: selected,
            onRowActivated: activated,
        });

        expect(api.getPinnedBottomRowCount()).toBe(1);
        expect(api.getPinnedBottomRow(0)?.data?.name).toBe('Total');

        const row = (index: number) =>
            document.querySelector<HTMLElement>(
                `.ag-grid-scrolling-container .ag-row[row-index="${index}"] .ag-cell`,
            )!;
        row(1).dispatchEvent(new MouseEvent('click', { bubbles: true }));
        await flushPromises();
        expect(selected).toHaveBeenCalledTimes(1);
        expect(selected.mock.calls[0][0]).toMatchObject({ id: 2 });

        row(0).dispatchEvent(new MouseEvent('dblclick', { bubbles: true }));
        await flushPromises();
        expect(activated).toHaveBeenCalledTimes(1);
        expect(activated.mock.calls[0][0]).toMatchObject({ id: 1 });

        api.setFocusedCell(1, 'name');
        await nextTick();
        const enter = new KeyboardEvent('keydown', {
            key: 'Enter',
            bubbles: true,
            cancelable: true,
        });
        document
            .querySelector(`.ag-row[row-index="1"] .ag-cell[col-id="name"]`)!
            .dispatchEvent(enter);
        await flushPromises();
        expect(activated).toHaveBeenCalledTimes(2);
        expect(activated.mock.calls[1][0]).toMatchObject({ id: 2 });

        const pinned = document.querySelector<HTMLElement>(
            '.ag-grid-pinned-bottom-rows-container .ag-row .ag-cell',
        )!;
        pinned.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        pinned.dispatchEvent(new MouseEvent('dblclick', { bubbles: true }));
        await flushPromises();
        expect(selected).toHaveBeenCalledTimes(1);
        expect(activated).toHaveBeenCalledTimes(2);
        wrapper.unmount();
    });
});
