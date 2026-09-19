<script setup lang="ts" generic="T extends Record<string, unknown>">
import type { CellKeyDownEvent, GridApi, GridReadyEvent } from 'ag-grid-community';
import { AgGridVue } from 'ag-grid-vue3';
import { shallowRef } from 'vue';
import { gridTheme } from '@/lib/agGrid';
import type { ColumnDef } from '@/lib/agGrid';

const props = defineProps<{ columns: ColumnDef<T>[]; newRow: () => T }>();
const rows = defineModel<T[]>('rows', { required: true });
const api = shallowRef<GridApi<T> | null>(null);

function emitRows(): void {
    const out: T[] = [];
    api.value?.forEachNode((n) => n.data && out.push(n.data));
    rows.value = out;
}

function firstEditableCol(): string | undefined {
    return props.columns.find((c) => c.editable)?.field as string | undefined;
}

function onCellKeyDown(e: CellKeyDownEvent<T>): void {
    const ke = e.event as KeyboardEvent | undefined;
    if (!ke || !api.value) {
        return;
    }
    const last = api.value.getDisplayedRowCount() - 1;
    if (ke.key === 'ArrowDown' && !ke.ctrlKey && e.rowIndex === last) {
        ke.preventDefault();
        api.value.applyTransaction({ add: [props.newRow()] });
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
    if (rows.value.length === 0) {
        e.api.applyTransaction({ add: [props.newRow()] });
        emitRows();
    }
}
</script>

<template>
    <AgGridVue
        class="ag-theme-app w-full"
        dom-layout="autoHeight"
        :theme="gridTheme"
        :row-data="rows"
        :column-defs="columns"
        :default-col-def="{ resizable: true, minWidth: 80 }"
        :single-click-edit="true"
        :stop-editing-when-cells-lose-focus="true"
        :enter-navigates-vertically="false"
        :enter-navigates-vertically-after-edit="false"
        @grid-ready="onReady"
        @cell-value-changed="emitRows"
        @cell-key-down="onCellKeyDown"
    />
</template>
