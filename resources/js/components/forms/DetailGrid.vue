<script setup lang="ts" generic="T extends Record<string, unknown>">
import type {
    CellKeyDownEvent,
    CellValueChangedEvent,
    GetRowIdParams,
    GridApi,
    GridReadyEvent,
} from 'ag-grid-community';
import { AgGridVue } from 'ag-grid-vue3';
import { shallowRef, watch } from 'vue';
import { gridTheme } from '@/lib/agGrid';
import type { ColumnDef } from '@/lib/agGrid';
import { newUid } from '@/lib/uid';

/**
 * An editable detail block (Oracle Forms' multi-record block): Down-arrow on the last row appends a blank one,
 * Ctrl+Delete removes the row. Every row carries a client `_uid` (assigned here when the page did not) and the grid
 * keys its nodes by it, so a `rows` change updates the nodes in place instead of rebuilding them.
 *
 * ag-grid-vue3 copies every row object before handing it to the grid, so a cell's `data` is never the object in
 * `rows`: pages patch their own model rows (found by `_uid`) and the grid follows through the `rows` prop; the
 * grid's own edits come back through `update:rows` as the node copies.
 *
 * The grid events this component handles itself are re-emitted (after its own handling) rather than left to fall
 * through: a fallthrough listener merged with the component's own becomes an array, which AG Grid does not call.
 * Any other grid event (`cell-focused`, …) falls through to the grid untouched.
 */
const props = defineProps<{ columns: ColumnDef<T>[]; newRow: () => T }>();
const emit = defineEmits<{
    cellKeyDown: [e: CellKeyDownEvent<T>];
    cellValueChanged: [e: CellValueChangedEvent<T>];
}>();
const rows = defineModel<T[]>('rows', { required: true });
const api = shallowRef<GridApi<T> | null>(null);

function withUid(row: T): T {
    if (typeof row._uid !== 'string' || row._uid === '') {
        (row as Record<string, unknown>)._uid = newUid();
    }
    return row;
}

function newRow(): T {
    return withUid(props.newRow());
}

// sync: the uids must be on the rows before the grid's own (pre-flush) rowData watcher copies them; a block
// without rows gets one blank row to type into (a new record, the last row removed)
watch(
    rows,
    (list) => {
        list.forEach(withUid);
        if (list.length === 0) {
            rows.value = [newRow()];
        }
    },
    { immediate: true, flush: 'sync' },
);

const getRowId = (p: GetRowIdParams<T>): string => String(p.data._uid);

function emitRows(): void {
    const out: T[] = [];
    api.value?.forEachNode((n) => n.data && out.push(n.data));
    rows.value = out;
}

function firstEditableCol(): string | undefined {
    return props.columns.find((c) => c.editable)?.field as string | undefined;
}

function onCellValueChanged(e: CellValueChangedEvent<T>): void {
    emitRows();
    emit('cellValueChanged', e);
}

function onCellKeyDown(e: CellKeyDownEvent<T>): void {
    const ke = e.event as KeyboardEvent | undefined;
    if (!ke || !api.value) {
        return;
    }
    // AG Grid prevents the default of a plain arrow key (its own navigation) before it dispatches cellKeyDown:
    // only a preventDefault() the page adds during the emit means it took the key (an F9 list, a Ctrl+arrow reorder)
    const grabbed = ke.defaultPrevented;
    emit('cellKeyDown', e);
    if (ke.defaultPrevented && !grabbed) {
        return;
    }
    const last = api.value.getDisplayedRowCount() - 1;
    if (ke.key === 'ArrowDown' && !ke.ctrlKey && e.rowIndex === last) {
        ke.preventDefault();
        api.value.applyTransaction({ add: [newRow()] });
        emitRows();
        const col = firstEditableCol();
        if (col) {
            api.value.setFocusedCell(last + 1, col);
            api.value.startEditingCell({ rowIndex: last + 1, colKey: col });
        }
    }
    if (ke.key === 'Delete' && ke.ctrlKey && e.data) {
        ke.preventDefault();
        ke.stopPropagation();
        api.value.applyTransaction({ remove: [e.data] });
        emitRows();
    }
}

function onReady(e: GridReadyEvent<T>): void {
    api.value = e.api;
}

/** Commits (or cancels) the cell being edited, so a save reads the typed value. */
function stopEditing(cancel = false): void {
    api.value?.stopEditing(cancel);
}

/** Re-renders every row: row class rules that depend on page state (error highlights) are evaluated again. */
function redrawRows(): void {
    api.value?.redrawRows();
}

defineExpose({ stopEditing, redrawRows });
</script>

<template>
    <AgGridVue
        class="ag-theme-app w-full"
        dom-layout="autoHeight"
        :theme="gridTheme"
        :row-data="rows"
        :get-row-id="getRowId"
        :column-defs="columns"
        :default-col-def="{ resizable: true, minWidth: 80 }"
        :single-click-edit="true"
        :stop-editing-when-cells-lose-focus="true"
        :enter-navigates-vertically="false"
        :enter-navigates-vertically-after-edit="false"
        @grid-ready="onReady"
        @cell-value-changed="onCellValueChanged"
        @cell-key-down="onCellKeyDown"
    />
</template>
