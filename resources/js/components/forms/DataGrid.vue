<script setup lang="ts" generic="T extends { id: number | string }">
import type { GridApi, GridReadyEvent, RowClickedEvent } from 'ag-grid-community';
import { AgGridVue } from 'ag-grid-vue3';
import { computed, shallowRef, watch } from 'vue';
import { gridTheme } from '@/lib/agGrid';
import type { ColumnDef } from '@/lib/agGrid';
import { t } from '@/lib/i18n';

const props = defineProps<{ rows: T[]; columns: ColumnDef<T>[]; selectedId?: number | string | null }>();
const emit = defineEmits<{ rowSelected: [row: T] }>();
const api = shallowRef<GridApi<T> | null>(null);
const autoHeight = computed(() => props.rows.length <= 15);

function onReady(e: GridReadyEvent<T>): void {
    api.value = e.api;
    syncSelection();
}

function syncSelection(): void {
    if (!api.value || props.selectedId === undefined || props.selectedId === null) {
        return;
    }
    const node = api.value.getRowNode(String(props.selectedId));
    if (node) {
        node.setSelected(true, true);
        api.value.ensureNodeVisible(node);
    }
}

watch(() => props.selectedId, syncSelection);
</script>

<template>
    <AgGridVue
        class="ag-theme-app w-full"
        :style="autoHeight ? '' : 'height: 480px'"
        :theme="gridTheme"
        :row-data="rows"
        :column-defs="columns"
        :default-col-def="{ sortable: true, resizable: true, filter: true, minWidth: 80 }"
        :get-row-id="(p) => String(p.data.id)"
        :row-selection="{ mode: 'singleRow', checkboxes: false, enableClickSelection: true }"
        :dom-layout="autoHeight ? 'autoHeight' : 'normal'"
        :overlay-no-rows-template="`<span>${t('forms.no_records')}</span>`"
        @grid-ready="onReady"
        @row-clicked="(e: RowClickedEvent<T>) => e.data && emit('rowSelected', e.data)"
    />
</template>
