import type { GridApi, RowClassParams } from 'ag-grid-community';
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

const TOTALS_CLASS = 'totals-row';

async function mountGrid(
    listeners: Record<string, (...args: unknown[]) => void>,
    props: Record<string, unknown> = {
        pinnedBottomRows: [{ id: 'total', name: 'Total', qty: 30 }],
        rowClassRules: {
            [TOTALS_CLASS]: (p: RowClassParams<Row>) =>
                p.node.rowPinned === 'bottom',
        },
    },
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
                    ...props,
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

        // Enter on the pinned totals row is inert too (the keyboard path, not just the mouse one)
        api.setFocusedCell(0, 'name', 'bottom');
        await nextTick();
        const pinnedEnter = new KeyboardEvent('keydown', {
            key: 'Enter',
            bubbles: true,
            cancelable: true,
        });
        pinned.dispatchEvent(pinnedEnter);
        await flushPromises();
        expect(activated).toHaveBeenCalledTimes(2);
        expect(pinnedEnter.defaultPrevented).toBe(false);
        wrapper.unmount();
    });

    it('applies rowClassRules to the rows, including the pinned totals row', async () => {
        const { wrapper } = await mountGrid({});
        const pinnedRow = document.querySelector(
            '.ag-grid-pinned-bottom-rows-container .ag-row',
        )!;
        const bodyRow = document.querySelector(
            '.ag-grid-scrolling-container .ag-row[row-index="0"]',
        )!;
        expect(pinnedRow.classList.contains(TOTALS_CLASS)).toBe(true);
        expect(bodyRow.classList.contains(TOTALS_CLASS)).toBe(false);
        wrapper.unmount();
    });

    it('selects and activates a single row with no pinned rows at all', async () => {
        const selected = vi.fn();
        const activated = vi.fn();
        const { wrapper, api } = await mountGrid(
            { onRowSelected: selected, onRowActivated: activated },
            {},
        );
        expect(api.getPinnedBottomRowCount()).toBe(0);

        const cell = (index: number) =>
            document.querySelector<HTMLElement>(
                `.ag-grid-scrolling-container .ag-row[row-index="${index}"] .ag-cell`,
            )!;
        cell(0).dispatchEvent(new MouseEvent('click', { bubbles: true }));
        cell(1).dispatchEvent(new MouseEvent('click', { bubbles: true }));
        await flushPromises();
        expect(selected).toHaveBeenCalledTimes(2);
        expect(selected.mock.calls[1][0]).toMatchObject({ id: 2 });
        // singleRow selection: only the last clicked row stays selected
        expect(api.getSelectedRows()).toEqual([{ id: 2, name: 'b', qty: 20 }]);

        cell(1).dispatchEvent(new MouseEvent('dblclick', { bubbles: true }));
        await flushPromises();
        // AG Grid's double-click goes through a timer of its own, which flushPromises() does not drain
        await vi.waitFor(() => {
            expect(activated).toHaveBeenCalledTimes(1);
            expect(activated.mock.calls[0][0]).toMatchObject({ id: 2 });
        });
        wrapper.unmount();
    });
});
