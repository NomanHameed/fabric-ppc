import type { GridApi } from 'ag-grid-community';
import { AgGridVue } from 'ag-grid-vue3';
import { flushPromises, mount } from '@vue/test-utils';
import { beforeAll, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick, ref } from 'vue';
import DetailGrid from '@/components/forms/DetailGrid.vue';
import { registerAgGrid } from '@/lib/agGrid';

type Row = { _uid?: string; name: string; qty: number | null };

beforeAll(() => {
    registerAgGrid();
    // jsdom has no ResizeObserver; the grid only needs it to exist
    globalThis.ResizeObserver ??= class {
        observe(): void {}
        unobserve(): void {}
        disconnect(): void {}
    } as unknown as typeof ResizeObserver;
});

async function mountGrid(
    initial: Row[],
    listeners: Record<string, (...args: unknown[]) => void> = {},
) {
    const rows = ref<Row[]>(initial);
    const Host = defineComponent({
        setup() {
            return () =>
                h(DetailGrid<Row>, {
                    rows: rows.value,
                    'onUpdate:rows': (v: Row[]) => (rows.value = v),
                    columns: [
                        { field: 'name', editable: true },
                        { field: 'qty', editable: true },
                    ],
                    newRow: () => ({ name: '', qty: null }),
                    ...listeners,
                });
        },
    });
    const wrapper = mount(Host, { attachTo: document.body });
    await flushPromises();
    await nextTick();
    const api = () =>
        (
            wrapper.findComponent(AgGridVue).vm as unknown as {
                api: GridApi<Row>;
            }
        ).api;
    return { wrapper, rows, api };
}

function nodeData(api: GridApi<Row>): Row[] {
    const out: Row[] = [];
    api.forEachNode((n) => n.data && out.push(n.data));
    return out;
}

/** The rendered cell of a row/column: what the keyboard event fires on (AG Grid handles it on the way up). */
function cellEl(rowIndex: number, colId: string): Element {
    const el = document.querySelector(
        `.ag-row[row-index="${rowIndex}"] .ag-cell[col-id="${colId}"]`,
    );
    if (!el) {
        throw new Error(`no rendered cell ${rowIndex}/${colId}`);
    }
    return el;
}

async function pressOn(
    rowIndex: number,
    colId: string,
    api: GridApi<Row>,
    init: KeyboardEventInit,
): Promise<KeyboardEvent> {
    api.setFocusedCell(rowIndex, colId);
    await nextTick();
    const ev = new KeyboardEvent('keydown', {
        bubbles: true,
        cancelable: true,
        ...init,
    });
    cellEl(rowIndex, colId).dispatchEvent(ev);
    await flushPromises();
    await nextTick();
    return ev;
}

describe('DetailGrid', () => {
    it('gives every row a _uid and keys the nodes by it', async () => {
        const { wrapper, rows, api } = await mountGrid([
            { name: 'a', qty: 1 },
            { name: 'b', qty: 2 },
        ]);
        expect(rows.value.map((r) => typeof r._uid)).toEqual([
            'string',
            'string',
        ]);
        expect(rows.value[0]._uid).not.toBe(rows.value[1]._uid);
        const nodes = nodeData(api());
        expect(nodes.map((n) => n._uid)).toEqual(rows.value.map((r) => r._uid));
        expect(api().getRowNode(rows.value[1]._uid!)?.data?.name).toBe('b');
        wrapper.unmount();
    });

    it('holds copies of the model rows, and a patch on the model row reaches the node', async () => {
        const { wrapper, rows, api } = await mountGrid([
            { name: 'a', qty: 1 },
            { name: 'b', qty: 2 },
        ]);
        const nodes = nodeData(api());
        // ag-grid-vue3 copies every row object: a cell's data is never the model object
        expect(nodes[0]).not.toBe(rows.value[0]);
        expect(nodes[0]).toEqual(rows.value[0]);

        rows.value[1].qty = 99;
        await flushPromises();
        await nextTick();
        await flushPromises();
        const after = nodeData(api());
        expect(after.map((n) => n.qty)).toEqual([1, 99]);
        expect(after[1]._uid).toBe(rows.value[1]._uid);
        expect(api().getRowNode(rows.value[1]._uid!)?.data?.qty).toBe(99);
        wrapper.unmount();
    });

    it('hands a cell edit back through update:rows and re-emits cell-value-changed', async () => {
        // a page listener merged with the component's own would reach AG Grid as an array (never called)
        const changed = vi.fn();
        const { wrapper, rows, api } = await mountGrid(
            [{ name: 'a', qty: 1 }],
            { onCellValueChanged: changed },
        );
        api().getRowNode(rows.value[0]._uid!)!.setDataValue('qty', 5);
        await flushPromises();
        await nextTick();
        expect(rows.value[0].qty).toBe(5);
        expect(changed).toHaveBeenCalledTimes(1);
        expect(changed.mock.calls[0][0]).toMatchObject({ newValue: 5 });
        wrapper.unmount();
    });

    it('keeps one blank row to type into: when mounted empty and when the rows are emptied later', async () => {
        const { wrapper, rows, api } = await mountGrid([]);
        expect(rows.value).toHaveLength(1);
        expect(typeof rows.value[0]._uid).toBe('string');
        expect(rows.value[0].name).toBe('');
        const first = rows.value[0]._uid;

        rows.value = []; // a new record replaces the block's rows
        await flushPromises();
        await nextTick();
        await flushPromises();
        expect(rows.value).toHaveLength(1);
        expect(rows.value[0]._uid).not.toBe(first);
        expect(nodeData(api()).map((n) => n._uid)).toEqual([
            rows.value[0]._uid,
        ]);
        wrapper.unmount();
    });

    it('appends a blank row on Down-arrow from the last row and starts editing it', async () => {
        // AG Grid prevents the default of a plain arrow key (its navigation) before cellKeyDown fires: that must
        // not read as the page having taken the key
        const { wrapper, rows, api } = await mountGrid([
            { name: 'a', qty: 1 },
            { name: 'b', qty: 2 },
        ]);
        const ev = await pressOn(1, 'name', api(), { key: 'ArrowDown' });
        expect(ev.defaultPrevented).toBe(true);
        expect(rows.value).toHaveLength(3);
        expect(rows.value[2]).toMatchObject({ name: '', qty: null });
        expect(typeof rows.value[2]._uid).toBe('string');
        expect(api().getDisplayedRowCount()).toBe(3);
        const editing = api().getEditingCells();
        expect(editing).toHaveLength(1);
        expect(editing[0].rowIndex).toBe(2);
        expect(editing[0].column?.getColId()).toBe('name');
        wrapper.unmount();
    });

    it('does not append on Down-arrow from a row that is not the last', async () => {
        const { wrapper, rows, api } = await mountGrid([
            { name: 'a', qty: 1 },
            { name: 'b', qty: 2 },
        ]);
        await pressOn(0, 'name', api(), { key: 'ArrowDown' });
        expect(rows.value).toHaveLength(2);
        expect(api().getEditingCells()).toHaveLength(0);
        wrapper.unmount();
    });

    it('removes the focused row on Ctrl+Delete', async () => {
        const { wrapper, rows, api } = await mountGrid([
            { name: 'a', qty: 1 },
            { name: 'b', qty: 2 },
            { name: 'c', qty: 3 },
        ]);
        const ev = await pressOn(1, 'qty', api(), {
            key: 'Delete',
            ctrlKey: true,
        });
        expect(ev.defaultPrevented).toBe(true);
        expect(rows.value.map((r) => r.name)).toEqual(['a', 'c']);
        expect(nodeData(api()).map((n) => n.name)).toEqual(['a', 'c']);
        wrapper.unmount();
    });

    it('leaves a key the page took (preventDefault in its cell-key-down listener) alone', async () => {
        const keyDown = vi.fn((e: { event?: Event }) => {
            const ke = e.event as KeyboardEvent;
            if (ke.key === 'ArrowDown' && ke.ctrlKey) {
                ke.preventDefault(); // a page reorder
            }
        });
        const { wrapper, rows, api } = await mountGrid(
            [
                { name: 'a', qty: 1 },
                { name: 'b', qty: 2 },
            ],
            { onCellKeyDown: keyDown as (...args: unknown[]) => void },
        );
        await pressOn(1, 'name', api(), { key: 'ArrowDown', ctrlKey: true });
        expect(keyDown).toHaveBeenCalledTimes(1);
        expect(rows.value).toHaveLength(2);
        expect(api().getEditingCells()).toHaveLength(0);
        wrapper.unmount();
    });
});
