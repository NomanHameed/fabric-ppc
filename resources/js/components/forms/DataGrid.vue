<script setup lang="ts" generic="T extends { id: number | string }">
import type {
    CellKeyDownEvent,
    GridApi,
    GridReadyEvent,
    RowClickedEvent,
    RowDoubleClickedEvent,
} from 'ag-grid-community';
import { AgGridVue } from 'ag-grid-vue3';
import { computed, shallowRef, watch } from 'vue';
import { gridTheme } from '@/lib/agGrid';
import type { ColumnDef } from '@/lib/agGrid';
import { t } from '@/lib/i18n';

const props = defineProps<{
    rows: T[];
    columns: ColumnDef<T>[];
    selectedId?: number | string | null;
    /** Rows pinned under the grid (a totals row); they never select or activate. */
    pinnedBottomRows?: T[];
}>();
/** rowSelected: a click on a row. rowActivated: a double-click or Enter on a row (open it). */
const emit = defineEmits<{ rowSelected: [row: T]; rowActivated: [row: T] }>();
const api = shallowRef<GridApi<T> | null>(null);
const autoHeight = computed(() => props.rows.length <= 15);

function onReady(e: GridReadyEvent<T>): void {
    api.value = e.api;
    syncSelection();
}

function syncSelection(): void {
    if (
        !api.value ||
        props.selectedId === undefined ||
        props.selectedId === null
    ) {
        return;
    }
    const node = api.value.getRowNode(String(props.selectedId));
    if (node) {
        node.setSelected(true, true);
        api.value.ensureNodeVisible(node);
    }
}

watch(() => props.selectedId, syncSelection);

function onCellKeyDown(e: CellKeyDownEvent<T>): void {
    const ke = e.event as KeyboardEvent | undefined;
    if (ke?.key === 'Enter' && e.data && !e.node.rowPinned) {
        ke.preventDefault();
        emit('rowActivated', e.data);
    }
}
</script>

<template>
    <AgGridVue
        class="ag-theme-app w-full"
        :style="autoHeight ? '' : 'height: 480px'"
        :theme="gridTheme"
        :row-data="rows"
        :pinned-bottom-row-data="pinnedBottomRows"
        :column-defs="columns"
        :default-col-def="{
            sortable: true,
            resizable: true,
            filter: true,
            minWidth: 80,
        }"
        :get-row-id="(p) => String(p.data.id)"
        :row-selection="{
            mode: 'singleRow',
            checkboxes: false,
            enableClickSelection: true,
        }"
        :dom-layout="autoHeight ? 'autoHeight' : 'normal'"
        :overlay-no-rows-template="`<span>${t('forms.no_records')}</span>`"
        @grid-ready="onReady"
        @row-clicked="
            (e: RowClickedEvent<T>) =>
                e.data && !e.node.rowPinned && emit('rowSelected', e.data)
        "
        @row-double-clicked="
            (e: RowDoubleClickedEvent<T>) =>
                e.data && !e.node.rowPinned && emit('rowActivated', e.data)
        "
        @cell-key-down="onCellKeyDown"
    />
</template>
