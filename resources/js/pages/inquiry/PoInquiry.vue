<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import type { RowClassParams } from 'ag-grid-community';
import { computed, ref, watch } from 'vue';
import DataGrid from '@/components/forms/DataGrid.vue';
import FormBlock from '@/components/forms/FormBlock.vue';
import FormField from '@/components/forms/FormField.vue';
import FormsToolbar from '@/components/forms/FormsToolbar.vue';
import { Input } from '@/components/ui/input';
import type { FormsAction } from '@/forms/keymap';
import type { ColumnDef } from '@/lib/agGrid';
import { formatQty } from '@/lib/dates';
import { t } from '@/lib/i18n';
import { showingText } from '@/lib/inquiry';
import { statusLine } from '@/lib/statusLine';
import type { InquiryContract, LotQuantities, PoLotRow } from '@/types/inquiry';

/**
 * PO Inquiry (spec §4.4 screen 4): the PO header and every lot of it with the position summary, label, process
 * flag and state, plus a pinned totals row. Read-only Forms look: a click selects a lot (Record n of m), a
 * double-click or Enter opens its Lot Inquiry; Print and Exit work, the rest of the toolbar is off.
 * The list is capped like the lot page's tabs ("showing n of m"); the totals row is the whole PO, not the page.
 */
const props = defineProps<{
    contract: InquiryContract;
    lots: PoLotRow[];
    lotsTotal: number;
    totals: LotQuantities;
}>();

const selectedId = ref<number | null>(null);
const showing = computed(() => showingText(props.lots.length, props.lotsTotal));
const index = computed(() =>
    props.lots.findIndex((l) => l.id === selectedId.value),
);

watch(
    [index, () => props.lots.length],
    ([i, n]) => statusLine.setRecord(i + 1, n),
    { immediate: true },
);

/** The totals row for the grid (T extends { id }); the string id keeps it apart from the lots' numeric ids. */
type GridRow = PoLotRow | (LotQuantities & { id: string; lot_no: string });
const totalsRow = computed<GridRow[]>(() => [
    { ...props.totals, id: 'total', lot_no: t('inquiry.po.total') },
]);

function open(row: GridRow): void {
    if (typeof row.id === 'number') {
        router.visit(`/inquiry/lots/${row.id}`);
    }
}

function onAction(action: FormsAction): void {
    switch (action) {
        case 'previousRecord':
        case 'nextRecord': {
            const i = index.value + (action === 'nextRecord' ? 1 : -1);
            const row = props.lots[i];
            if (row) {
                selectedId.value = row.id;
            }
            break;
        }
        case 'print':
            window.print();
            break;
        case 'exit':
            router.visit('/dashboard');
            break;
    }
}

const qty = (p: { value: unknown }): string => formatQty(p.value);
const qtyColumn = (
    field: keyof LotQuantities,
    key: string,
): ColumnDef<GridRow> => ({
    field,
    headerName: t(`inquiry.fields.${key}`),
    width: 120,
    type: 'numericColumn',
    valueFormatter: qty,
});

const columns = computed<ColumnDef<GridRow>[]>(() => [
    {
        field: 'lot_no',
        headerName: t('inquiry.fields.lot_no'),
        width: 100,
        pinned: 'left',
    },
    { field: 'design', headerName: t('inquiry.fields.design'), flex: 1 },
    { field: 'colour', headerName: t('inquiry.fields.colour'), flex: 1 },
    { field: 'quality', headerName: t('inquiry.fields.quality'), flex: 2 },
    { field: 'po_type', headerName: t('inquiry.fields.po_type'), width: 100 },
    qtyColumn('greige_qty', 'greige'),
    qtyColumn('stock_qty', 'stock'),
    qtyColumn('issued_qty', 'issued'),
    qtyColumn('wip_qty', 'wip'),
    qtyColumn('finished_qty', 'finished'),
    qtyColumn('dispatched_qty', 'dispatched'),
    qtyColumn('loss_qty', 'loss'),
    {
        field: 'current_label',
        headerName: t('inquiry.fields.label'),
        width: 130,
        cellClass: 'font-mono',
    },
    {
        field: 'process_flag',
        headerName: t('inquiry.fields.process'),
        width: 100,
        valueFormatter: (p) =>
            p.value ? t(`inquiry.lot.process_flag.${String(p.value)}`) : '',
    },
    {
        field: 'state',
        headerName: t('inquiry.fields.state'),
        width: 100,
        valueFormatter: (p) =>
            p.value ? t(`production.states.${String(p.value)}`) : '',
    },
    {
        field: 'colour_status',
        headerName: t('inquiry.fields.colour_status'),
        width: 120,
    },
]);

const rowClassRules = {
    'font-semibold': (p: RowClassParams<GridRow>) =>
        p.node.rowPinned === 'bottom',
    'text-muted-foreground': (p: RowClassParams<GridRow>) =>
        p.data !== undefined &&
        'state' in p.data &&
        (p.data.state === 'cancelled' || p.data.state === 'closed'),
};
</script>

<template>
    <Head :title="t('inquiry.po.title', { po: String(contract.po_no) })" />
    <div class="flex flex-col gap-3 p-4">
        <h1 class="text-lg font-semibold">
            {{ t('inquiry.po.heading') }}
            <span class="tabular-nums">{{ contract.po_no }}</span>
        </h1>
        <FormsToolbar
            :disabled="{
                save: true,
                newRecord: true,
                deleteRecord: true,
                enterQuery: true,
                executeQuery: true,
                clear: true,
                previousRecord: index <= 0,
                nextRecord: index >= lots.length - 1,
            }"
            @action="onAction"
        />

        <FormBlock :title="t('inquiry.po.header')">
            <FormField :label="t('inquiry.fields.po_no')" :span="2">
                <Input
                    :model-value="String(contract.po_no)"
                    readonly
                    class="tabular-nums"
                />
            </FormField>
            <FormField :label="t('inquiry.fields.party')" :span="4">
                <Input :model-value="contract.party" readonly />
            </FormField>
            <FormField :label="t('inquiry.fields.ref_no')" :span="2">
                <Input :model-value="contract.ref_no ?? ''" readonly />
            </FormField>
            <FormField :label="t('inquiry.fields.po_date')" :span="2">
                <Input
                    :model-value="contract.po_date"
                    readonly
                    class="tabular-nums"
                />
            </FormField>
            <FormField :label="t('inquiry.fields.contract_qty')" :span="2">
                <Input
                    :model-value="formatQty(contract.contract_qty)"
                    readonly
                    class="text-end tabular-nums"
                />
            </FormField>
            <FormField :label="t('inquiry.po.lots_count')" :span="2">
                <Input
                    :model-value="String(contract.lots_count)"
                    readonly
                    class="text-end tabular-nums"
                />
            </FormField>
            <p
                v-if="contract.oracle_missing_at"
                class="text-destructive col-span-12 text-xs"
            >
                {{
                    t('inquiry.lot.missing_hint', {
                        at: contract.oracle_missing_at,
                    })
                }}
            </p>
        </FormBlock>

        <div class="flex flex-col gap-1">
            <div class="flex items-center gap-2">
                <h2
                    class="text-muted-foreground text-xs font-semibold tracking-wide uppercase"
                >
                    {{ t('inquiry.po.lots') }}
                </h2>
                <span
                    v-if="showing"
                    class="text-muted-foreground ms-auto text-xs tabular-nums"
                    >{{ showing }}</span
                >
            </div>
            <p v-if="lots.length === 0" class="text-muted-foreground text-sm">
                {{ t('inquiry.po.no_lots') }}
            </p>
            <DataGrid
                v-else
                :rows="lots as GridRow[]"
                :columns="columns"
                :selected-id="selectedId"
                :pinned-bottom-rows="totalsRow"
                :row-class-rules="rowClassRules"
                @row-selected="
                    (r) =>
                        (selectedId =
                            typeof r.id === 'number' ? r.id : selectedId)
                "
                @row-activated="open"
            />
            <p class="text-muted-foreground text-xs">
                {{ t('inquiry.po.open_hint') }}
            </p>
        </div>
    </div>
</template>
