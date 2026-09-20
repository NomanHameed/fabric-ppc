<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import ConfirmSaveDialog from '@/components/forms/ConfirmSaveDialog.vue';
import DataGrid from '@/components/forms/DataGrid.vue';
import FormBlock from '@/components/forms/FormBlock.vue';
import FormField from '@/components/forms/FormField.vue';
import FormsToolbar from '@/components/forms/FormsToolbar.vue';
import LovField from '@/components/forms/LovField.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import type { FormsAction } from '@/forms/keymap';
import type { ColumnDef } from '@/lib/agGrid';
import { formatQty, todayDmy } from '@/lib/dates';
import { t } from '@/lib/i18n';
import type { LovRow } from '@/lib/lov';
import { statusLine } from '@/lib/statusLine';
import type { ContractSummary, IssueRow, LotSummary } from '@/types/production';

/**
 * Oracle-Forms shape: the recorded issues are the block's records (Record n of m, Previous/Next, grid
 * row click, all through the unsaved guard); a recorded issue is read-only (the ledger is insert-only,
 * corrections are reversals); New record / Clear open a blank record to issue a lot; the PO block issues
 * every open lot of a PO in one go.
 */
const props = defineProps<{ recent: IssueRow[] }>();

type IssueForm = {
    lot_id: number | null;
    qty: string | number;
    issued_on: string;
    remarks: string;
};
type LotView = Pick<
    LotSummary,
    'party' | 'quality' | 'design' | 'colour' | 'stock_qty'
>;

const blank = (): IssueForm => ({
    lot_id: null,
    qty: '',
    issued_on: todayDmy(),
    remarks: '',
});
const fromRow = (r: IssueRow): IssueForm => ({
    lot_id: r.lot_id,
    qty: r.qty,
    issued_on: r.moved_at,
    remarks: r.remarks ?? '',
});

const currentId = ref<number | null>(null); // null: entering a new issue
const lot = ref<LotView | null>(null);
const lotDisplay = ref('');
const form = useForm<IssueForm>(blank());

const contract = ref<ContractSummary | null>(null);
const contractDisplay = ref('');
const bulk = useForm({
    contract_id: null as number | null,
    issued_on: todayDmy(),
});

const index = computed(() =>
    props.recent.findIndex((r) => r.id === currentId.value),
);
const viewing = computed(() => currentId.value !== null);

function load(id: number | null): void {
    currentId.value = id;
    const row =
        id === null ? null : (props.recent.find((r) => r.id === id) ?? null);
    form.defaults(row ? fromRow(row) : blank());
    form.reset();
    form.clearErrors();
    lot.value = row
        ? {
              party: row.party,
              quality: row.quality ?? '',
              design: row.design,
              colour: row.colour,
              stock_qty: row.stock_qty,
          }
        : null;
    lotDisplay.value = row
        ? `${row.lot_no} · ${row.po_no} · ${row.design ?? ''} · ${row.colour ?? ''}`
        : '';
    statusLine.setMessage(row ? t('production.issue.viewing') : '', 'info');
}

watch(
    [index, () => props.recent.length],
    ([i, n]) => statusLine.setRecord(i + 1, n),
    { immediate: true },
);
watch(
    () => form.isDirty,
    (d) => statusLine.setDirty(d),
    { immediate: true },
);
watch(
    () => props.recent,
    (rows) => {
        // A viewed record is re-read from the refreshed rows (its lot's stock may have changed) or,
        // when it fell off the list, replaced by a blank one; a record being entered is left alone.
        if (currentId.value !== null) {
            load(
                rows.some((r) => r.id === currentId.value)
                    ? currentId.value
                    : null,
            );
        }
    },
);

function onLot(row: LovRow): void {
    const picked = row as unknown as LotSummary;
    lot.value = picked;
    form.qty = picked.stock_qty;
    statusLine.setHint(
        t('production.issue.available_hint', {
            qty: formatQty(picked.stock_qty),
        }),
    );
}

function onContract(row: LovRow): void {
    contract.value = row as unknown as ContractSummary;
}

function save(): Promise<boolean> {
    return new Promise((resolve) => {
        if (viewing.value || !form.isDirty) {
            statusLine.setMessage(t('forms.nothing_to_save'), 'info');
            resolve(false);
            return;
        }
        form.post('/production/issue', {
            preserveScroll: true,
            onSuccess: () => {
                statusLine.setMessage(t('forms.saved'), 'success');
                load(null);
                resolve(true);
            },
            onError: () => {
                statusLine.setMessage(
                    Object.values(form.errors)[0] ?? '',
                    'error',
                );
                resolve(false);
            },
        });
    });
}

function issueContract(): void {
    if (
        bulk.contract_id === null ||
        !window.confirm(
            t('production.issue.confirm_all', {
                po: contract.value?.code ?? '',
            }),
        )
    ) {
        return;
    }
    bulk.post('/production/issue/contract', {
        preserveScroll: true,
        onSuccess: () => {
            statusLine.setMessage(t('forms.saved'), 'success');
            bulk.reset();
            bulk.clearErrors();
            contract.value = null;
            contractDisplay.value = '';
        },
        onError: () =>
            statusLine.setMessage(Object.values(bulk.errors)[0] ?? '', 'error'),
    });
}

const guard = useUnsavedGuard(() => form.isDirty, save);

function onAction(action: FormsAction): void {
    switch (action) {
        case 'save':
            void save();
            break;
        case 'newRecord':
            guard.guard(() => load(null));
            break;
        case 'previousRecord':
            if (index.value > 0) {
                guard.guard(() => load(props.recent[index.value - 1].id));
            }
            break;
        case 'nextRecord':
            if (index.value < props.recent.length - 1) {
                guard.guard(() => load(props.recent[index.value + 1].id));
            }
            break;
        case 'clear':
            // Oracle Forms' Clear prompts Save/Discard/Cancel before dropping edits.
            guard.guard(() => load(currentId.value));
            break;
        case 'print':
            window.print();
            break;
        case 'exit':
            router.visit('/dashboard');
            break;
    }
}

const columns = computed<ColumnDef<IssueRow>[]>(() => [
    { field: 'moved_at', headerName: t('production.fields.date'), flex: 1 },
    { field: 'lot_no', headerName: t('production.fields.lot_no'), flex: 1 },
    { field: 'po_no', headerName: t('production.fields.po_no'), flex: 1 },
    { field: 'party', headerName: t('production.fields.party'), flex: 2 },
    { field: 'design', headerName: t('production.fields.design'), flex: 1 },
    { field: 'colour', headerName: t('production.fields.colour'), flex: 1 },
    {
        field: 'qty',
        headerName: t('production.fields.qty'),
        flex: 1,
        type: 'numericColumn',
        valueFormatter: (p) => formatQty(p.value),
    },
    {
        field: 'reversal',
        headerName: t('production.fields.reversal'),
        flex: 1,
        valueFormatter: (p) => (p.value ? '✓' : ''),
    },
    { field: 'user', headerName: t('production.fields.user'), flex: 1 },
    { field: 'remarks', headerName: t('production.fields.remarks'), flex: 2 },
]);
</script>

<template>
    <Head :title="t('production.issue.title')" />
    <div class="flex flex-col gap-3 p-4">
        <h1 class="text-lg font-semibold">{{ t('production.issue.title') }}</h1>
        <FormsToolbar
            :disabled="{
                save: viewing,
                deleteRecord: true,
                enterQuery: true,
                executeQuery: true,
                previousRecord: index <= 0,
                nextRecord: index >= recent.length - 1,
            }"
            @action="onAction"
        />
        <FormBlock :title="t('production.issue.per_lot')">
            <FormField
                :label="`${t('production.fields.lot_no')} *`"
                for="f-lot"
                :span="4"
                :error="form.errors.lot_id"
                :hint="t('production.issue.lot_hint')"
            >
                <LovField
                    id="f-lot"
                    v-model="form.lot_id"
                    v-model:display="lotDisplay"
                    name="lots"
                    :disabled="viewing"
                    @selected="onLot"
                />
            </FormField>
            <FormField :label="t('production.fields.party')" :span="4">
                <Input :model-value="lot?.party ?? ''" disabled />
            </FormField>
            <FormField :label="t('production.fields.quality')" :span="4">
                <Input :model-value="lot?.quality ?? ''" disabled />
            </FormField>
            <FormField :label="t('production.fields.design')" :span="3">
                <Input :model-value="lot?.design ?? ''" disabled />
            </FormField>
            <FormField :label="t('production.fields.colour')" :span="3">
                <Input :model-value="lot?.colour ?? ''" disabled />
            </FormField>
            <FormField :label="t('production.issue.available')" :span="2">
                <Input
                    :model-value="lot ? formatQty(lot.stock_qty) : ''"
                    disabled
                    class="text-end tabular-nums"
                />
            </FormField>
            <FormField
                :label="`${t('production.fields.qty')} *`"
                for="f-qty"
                :span="2"
                :error="form.errors.qty"
            >
                <Input
                    id="f-qty"
                    v-model="form.qty"
                    type="number"
                    step="0.01"
                    min="0.01"
                    :disabled="viewing"
                    class="text-end tabular-nums"
                />
            </FormField>
            <FormField
                :label="`${t('production.fields.date')} *`"
                for="f-date"
                :span="2"
                :error="form.errors.issued_on"
            >
                <Input
                    id="f-date"
                    v-model="form.issued_on"
                    placeholder="dd-mm-yyyy"
                    :disabled="viewing"
                />
            </FormField>
            <FormField
                :label="t('production.fields.remarks')"
                for="f-remarks"
                :span="12"
                :error="form.errors.remarks"
            >
                <Input
                    id="f-remarks"
                    v-model="form.remarks"
                    :disabled="viewing"
                />
            </FormField>
        </FormBlock>

        <FormBlock :title="t('production.issue.per_po')">
            <FormField
                :label="t('production.fields.po_no')"
                for="f-po"
                :span="3"
                :error="bulk.errors.contract_id"
                :hint="t('production.issue.po_hint')"
            >
                <LovField
                    id="f-po"
                    v-model="bulk.contract_id"
                    v-model:display="contractDisplay"
                    name="contracts"
                    @selected="onContract"
                />
            </FormField>
            <FormField :label="t('production.fields.party')" :span="3">
                <Input :model-value="contract?.party ?? ''" disabled />
            </FormField>
            <FormField :label="t('production.fields.ref_no')" :span="2">
                <Input :model-value="contract?.ref_no ?? ''" disabled />
            </FormField>
            <FormField
                :label="t('production.fields.date')"
                for="f-po-date"
                :span="2"
                :error="bulk.errors.issued_on"
            >
                <Input
                    id="f-po-date"
                    v-model="bulk.issued_on"
                    placeholder="dd-mm-yyyy"
                />
            </FormField>
            <div class="col-span-12 flex items-end md:col-span-2">
                <Button
                    type="button"
                    variant="secondary"
                    class="w-full"
                    :disabled="bulk.contract_id === null || bulk.processing"
                    @click="issueContract"
                >
                    {{ t('production.issue.issue_all') }}
                </Button>
            </div>
        </FormBlock>

        <h2
            class="text-muted-foreground text-xs font-semibold tracking-wide uppercase"
        >
            {{ t('production.issue.recent') }}
        </h2>
        <DataGrid
            :rows="recent"
            :columns="columns"
            :selected-id="currentId"
            @row-selected="(r) => guard.guard(() => load(r.id))"
        />
        <ConfirmSaveDialog
            :open="guard.dialogOpen.value"
            @choose="guard.choose"
        />
    </div>
</template>
