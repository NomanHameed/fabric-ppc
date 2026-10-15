<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import DataGrid from '@/components/forms/DataGrid.vue';
import FormBlock from '@/components/forms/FormBlock.vue';
import FormField from '@/components/forms/FormField.vue';
import FormsToolbar from '@/components/forms/FormsToolbar.vue';
import { Input } from '@/components/ui/input';
import { useQueryMode } from '@/composables/useQueryMode';
import type { FormsAction } from '@/forms/keymap';
import type { ColumnDef } from '@/lib/agGrid';
import { formatQty } from '@/lib/dates';
import { t } from '@/lib/i18n';
import { statusLine } from '@/lib/statusLine';
import type { ContractScreenRow } from '@/types/production';

/**
 * The POs / Contracts screen (spec §4.4 screen 4, §4.2): one Oracle-Forms record per PO. Read-only — Oracle owns
 * every column of a contract — so Save, New and Delete are off; F7/F8 query, Previous/Next walk the results,
 * Print and Exit work. Each row links to the PO Inquiry (double-click, Enter, or the button on the form block).
 * The lot totals beside each PO are summed in the database over every lot of it, not over the capped page.
 */
const props = defineProps<{
    contracts: ContractScreenRow[];
    contractsTotal: number;
    criteria: Record<string, string>;
}>();

const CRITERIA_FIELDS = [
    'po_no',
    'party',
    'ref_no',
    'po_date',
    'oracle_missing_at',
] as const;

const currentId = ref<number | null>(props.contracts[0]?.id ?? null);
const current = computed<ContractScreenRow | null>(
    () => props.contracts.find((c) => c.id === currentId.value) ?? null,
);
const index = computed(() =>
    props.contracts.findIndex((c) => c.id === currentId.value),
);
const showing = computed(() =>
    props.contracts.length < props.contractsTotal
        ? t('production.contracts.showing', {
              n: props.contracts.length,
              m: props.contractsTotal,
          })
        : '',
);

const query = useQueryMode<Record<string, string | null>>([...CRITERIA_FIELDS]);
const criteria = query.criteria;
const inQuery = computed(() => query.mode.value === 'enterQuery');

// a fresh result set re-selects its first PO; the record position follows the selection
watch(
    () => props.contracts,
    (rows) => {
        if (!rows.some((c) => c.id === currentId.value)) {
            currentId.value = rows[0]?.id ?? null;
        }
    },
    { immediate: true },
);
watch(
    [index, () => props.contracts.length],
    ([i, n]) => statusLine.setRecord(i + 1, n),
    { immediate: true },
);

function open(id: number | null): void {
    if (id !== null) {
        router.visit(`/inquiry/contracts/${id}`);
    }
}

function executeQuery(): void {
    const q = inQuery.value ? query.takeCriteria() : {};
    router.get(
        '/production/contracts',
        { q },
        {
            only: ['contracts', 'contractsTotal', 'criteria'],
            preserveState: true,
            preserveScroll: true,
            // a criterion the server rejected must tell the user, not silently drop query mode
            onError: (errors) => {
                statusLine.setMessage(Object.values(errors)[0] ?? '', 'error');
                query.enterQuery();
            },
        },
    );
}

function onAction(action: FormsAction): void {
    switch (action) {
        case 'enterQuery':
            query.enterQuery();
            break;
        case 'executeQuery':
            executeQuery();
            break;
        case 'previousRecord':
            if (index.value > 0) {
                currentId.value = props.contracts[index.value - 1].id;
            }
            break;
        case 'nextRecord':
            if (index.value < props.contracts.length - 1) {
                currentId.value = props.contracts[index.value + 1].id;
            }
            break;
        case 'clear':
            query.cancelQuery();
            break;
        case 'print':
            window.print();
            break;
        case 'exit':
            router.visit('/dashboard');
            break;
    }
}

const qty = (p: { value: unknown }): string => formatQty(p.value);
const qtyColumn = (field: keyof ContractScreenRow, key: string) => ({
    field,
    headerName: t(`production.fields.${key}`),
    width: 130,
    type: 'numericColumn',
    valueFormatter: qty,
});

const columns = computed<ColumnDef<ContractScreenRow>[]>(() => [
    {
        field: 'po_no',
        headerName: t('production.fields.po_no'),
        width: 110,
        pinned: 'left',
        type: 'numericColumn',
    },
    { field: 'party', headerName: t('production.fields.party'), flex: 2 },
    { field: 'ref_no', headerName: t('production.fields.ref_no'), flex: 1 },
    {
        field: 'po_date',
        headerName: t('production.fields.po_date'),
        width: 110,
        cellClass: 'tabular-nums',
    },
    qtyColumn('contract_qty', 'contract_qty'),
    {
        field: 'lots_count',
        headerName: t('production.contracts.lots_count'),
        width: 90,
        type: 'numericColumn',
    },
    qtyColumn('greige_received_oracle', 'greige'),
    qtyColumn('greige_in_stock_oracle', 'stock'),
    qtyColumn('qty_issued', 'issued'),
    qtyColumn('wip', 'wip'),
    qtyColumn('finished', 'finished'),
    qtyColumn('dispatch', 'dispatched'),
    qtyColumn('loss_qty', 'loss'),
    {
        field: 'oracle_missing_at',
        headerName: t('production.fields.oracle_missing_at'),
        width: 130,
        cellClass: 'tabular-nums',
    },
]);

const TOTAL_FIELDS = [
    'greige_received_oracle',
    'greige_in_stock_oracle',
    'qty_issued',
    'wip',
    'finished',
    'dispatch',
    'loss_qty',
] as const;
const TOTAL_LABELS: Record<(typeof TOTAL_FIELDS)[number], string> = {
    greige_received_oracle: 'greige',
    greige_in_stock_oracle: 'stock',
    qty_issued: 'issued',
    wip: 'wip',
    finished: 'finished',
    dispatch: 'dispatched',
    loss_qty: 'loss',
};
</script>

<template>
    <Head :title="t('production.contracts.title')" />
    <div class="flex min-w-0 flex-col gap-3 p-3 sm:p-4">
        <div class="flex flex-wrap items-center gap-2">
            <h1 class="text-lg font-semibold">
                {{ t('production.contracts.title') }}
            </h1>
            <span
                v-if="showing"
                class="text-muted-foreground ms-auto text-xs tabular-nums"
                >{{ showing }}</span
            >
        </div>
        <FormsToolbar
            :mode="query.mode.value"
            :disabled="{
                save: true,
                newRecord: true,
                deleteRecord: true,
                clear: !inQuery,
                previousRecord: index <= 0,
                nextRecord: index >= contracts.length - 1,
            }"
            @action="onAction"
        />

        <FormBlock v-if="inQuery" :title="t('forms.query_mode')">
            <FormField
                v-for="f in CRITERIA_FIELDS"
                :key="f"
                :label="t(`production.fields.${f}`)"
                :for="`q-${f}`"
                :span="3"
            >
                <Input
                    :id="`q-${f}`"
                    v-model="criteria[f] as string"
                    class="border-warning/70"
                    autocomplete="off"
                />
            </FormField>
        </FormBlock>

        <FormBlock
            v-else-if="current"
            :title="t('production.contracts.header')"
        >
            <FormField :label="t('production.fields.po_no')" :span="2">
                <Input
                    :model-value="String(current.po_no)"
                    readonly
                    class="tabular-nums"
                />
            </FormField>
            <FormField :label="t('production.fields.party')" :span="4">
                <Input :model-value="current.party" readonly />
            </FormField>
            <FormField :label="t('production.fields.ref_no')" :span="2">
                <Input :model-value="current.ref_no ?? ''" readonly />
            </FormField>
            <FormField :label="t('production.fields.po_date')" :span="2">
                <Input
                    :model-value="current.po_date"
                    readonly
                    class="tabular-nums"
                />
            </FormField>
            <FormField
                :label="t('production.contracts.contract_qty')"
                :span="2"
            >
                <Input
                    :model-value="formatQty(current.contract_qty)"
                    readonly
                    class="text-end tabular-nums"
                />
            </FormField>
            <FormField :label="t('production.contracts.lots_count')" :span="2">
                <Input
                    :model-value="String(current.lots_count)"
                    readonly
                    class="text-end tabular-nums"
                />
            </FormField>
            <FormField
                v-for="f in TOTAL_FIELDS"
                :key="f"
                :label="t(`production.fields.${TOTAL_LABELS[f]}`)"
                :span="2"
            >
                <Input
                    :model-value="formatQty(current[f])"
                    readonly
                    class="text-end tabular-nums"
                />
            </FormField>
            <div class="col-span-12 flex flex-wrap items-center gap-3">
                <Link
                    class="text-primary text-sm underline"
                    :href="`/inquiry/contracts/${current.id}`"
                    >{{ t('production.contracts.inquiry') }}</Link
                >
                <span
                    v-if="current.oracle_missing_at"
                    class="text-destructive text-xs"
                >
                    {{
                        t('production.contracts.missing', {
                            date: current.oracle_missing_at,
                        })
                    }}
                </span>
            </div>
        </FormBlock>

        <p v-else class="text-muted-foreground text-sm">
            {{ t('production.contracts.none') }}
        </p>

        <DataGrid
            v-if="contracts.length > 0"
            :rows="contracts"
            :columns="columns"
            :selected-id="currentId"
            @row-selected="(r) => (currentId = r.id)"
            @row-activated="(r) => open(r.id)"
        />
        <p v-if="contracts.length > 0" class="text-muted-foreground text-xs">
            {{ t('production.contracts.open_hint') }}
        </p>
    </div>
</template>
