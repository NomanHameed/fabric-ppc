<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import DataGrid from '@/components/forms/DataGrid.vue';
import FormsToolbar from '@/components/forms/FormsToolbar.vue';
import type { FormsAction } from '@/forms/keymap';
import type { ColumnDef } from '@/lib/agGrid';
import { formatQty } from '@/lib/dates';
import { t } from '@/lib/i18n';
import { showingText } from '@/lib/inquiry';
import { statusLine } from '@/lib/statusLine';
import type { SearchContractRow, SearchLotRow } from '@/types/inquiry';

/**
 * The chooser behind the top-bar search (spec §6.3.2): several lots sharing a number (or a number that is both
 * a lot and a PO) are listed with PO + party + design + colour + state + label; a single click opens the inquiry
 * (the one navigation path, as `inquiry.search.open_hint` says).
 * With nothing matching (or no query) it explains what the box takes.
 */
const props = defineProps<{
    q: string;
    lots: SearchLotRow[];
    lotsTotal: number;
    contracts: SearchContractRow[];
}>();

const empty = computed(
    () => props.lots.length === 0 && props.contracts.length === 0,
);
const showing = computed(() => showingText(props.lots.length, props.lotsTotal));

watch(
    () => props.lots.length + props.contracts.length,
    (n) => statusLine.setRecord(n > 0 ? 1 : 0, n),
    { immediate: true },
);

function onAction(action: FormsAction): void {
    if (action === 'print') {
        window.print();
    } else if (action === 'exit') {
        router.visit('/dashboard');
    }
}

const qty = (p: { value: unknown }): string => formatQty(p.value);

const lotColumns = computed<ColumnDef<SearchLotRow>[]>(() => [
    { field: 'lot_no', headerName: t('inquiry.fields.lot_no'), width: 100 },
    { field: 'po_no', headerName: t('inquiry.fields.po_no'), width: 100 },
    { field: 'party', headerName: t('inquiry.fields.party'), flex: 2 },
    { field: 'design', headerName: t('inquiry.fields.design'), flex: 1 },
    { field: 'colour', headerName: t('inquiry.fields.colour'), flex: 1 },
    { field: 'quality', headerName: t('inquiry.fields.quality'), flex: 2 },
    {
        field: 'state',
        headerName: t('inquiry.fields.state'),
        width: 100,
        valueFormatter: (p) =>
            p.value ? t(`production.states.${String(p.value)}`) : '',
    },
    {
        field: 'current_label',
        headerName: t('inquiry.fields.label'),
        width: 130,
        cellClass: 'font-mono',
    },
    {
        field: 'stock_qty',
        headerName: t('inquiry.fields.stock'),
        width: 120,
        type: 'numericColumn',
        valueFormatter: qty,
    },
    {
        field: 'wip_qty',
        headerName: t('inquiry.fields.wip'),
        width: 110,
        type: 'numericColumn',
        valueFormatter: qty,
    },
    {
        field: 'finished_qty',
        headerName: t('inquiry.fields.finished'),
        width: 110,
        type: 'numericColumn',
        valueFormatter: qty,
    },
]);

const contractColumns = computed<ColumnDef<SearchContractRow>[]>(() => [
    { field: 'po_no', headerName: t('inquiry.fields.po_no'), width: 110 },
    { field: 'party', headerName: t('inquiry.fields.party'), flex: 2 },
    { field: 'ref_no', headerName: t('inquiry.fields.ref_no'), flex: 1 },
    { field: 'po_date', headerName: t('inquiry.fields.po_date'), width: 120 },
]);
</script>

<template>
    <Head
        :title="
            q ? t('inquiry.search.title', { q }) : t('inquiry.search.heading')
        "
    />
    <div class="flex flex-col gap-3 p-4">
        <h1 class="text-lg font-semibold">
            {{
                q
                    ? t('inquiry.search.title', { q })
                    : t('inquiry.search.heading')
            }}
        </h1>
        <FormsToolbar
            :disabled="{
                save: true,
                newRecord: true,
                deleteRecord: true,
                enterQuery: true,
                executeQuery: true,
                clear: true,
                previousRecord: true,
                nextRecord: true,
            }"
            @action="onAction"
        />

        <p v-if="empty" class="text-muted-foreground text-sm" data-search-empty>
            {{
                q ? t('inquiry.search.none', { q }) : t('inquiry.search.empty')
            }}
        </p>

        <template v-else>
            <p class="text-muted-foreground text-xs">
                {{ t('inquiry.search.open_hint') }}
            </p>
            <div v-if="lots.length" class="flex flex-col gap-1">
                <div class="flex items-center gap-2">
                    <h2
                        class="text-muted-foreground text-xs font-semibold tracking-wide uppercase"
                    >
                        {{ t('inquiry.search.lots') }}
                    </h2>
                    <span
                        v-if="lots.length > 1"
                        class="text-muted-foreground text-xs"
                    >
                        {{ t('inquiry.search.several', { n: lotsTotal }) }}
                    </span>
                    <span
                        v-if="showing"
                        class="text-muted-foreground ms-auto text-xs tabular-nums"
                        >{{ showing }}</span
                    >
                </div>
                <DataGrid
                    :rows="lots"
                    :columns="lotColumns"
                    @row-selected="(r) => router.visit(`/inquiry/lots/${r.id}`)"
                />
            </div>
            <div v-if="contracts.length" class="flex flex-col gap-1">
                <h2
                    class="text-muted-foreground text-xs font-semibold tracking-wide uppercase"
                >
                    {{ t('inquiry.search.contracts') }}
                </h2>
                <DataGrid
                    :rows="contracts"
                    :columns="contractColumns"
                    @row-selected="
                        (r) => router.visit(`/inquiry/contracts/${r.id}`)
                    "
                />
            </div>
        </template>
    </div>
</template>
