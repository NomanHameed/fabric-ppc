<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import type {
    CellKeyDownEvent,
    ICellRendererParams,
    RowClassParams,
    ValueGetterParams,
} from 'ag-grid-community';
import { computed, ref, watch } from 'vue';
import ConfirmSaveDialog from '@/components/forms/ConfirmSaveDialog.vue';
import DetailGrid from '@/components/forms/DetailGrid.vue';
import FormBlock from '@/components/forms/FormBlock.vue';
import FormField from '@/components/forms/FormField.vue';
import FormsToolbar from '@/components/forms/FormsToolbar.vue';
import LovDialog from '@/components/forms/LovDialog.vue';
import LovField from '@/components/forms/LovField.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import type { FormsAction } from '@/forms/keymap';
import type { ColumnDef } from '@/lib/agGrid';
import { formatQty } from '@/lib/dates';
import { t } from '@/lib/i18n';
import type { LovRow } from '@/lib/lov';
import {
    blankStepRow,
    errorGridRowIndexes,
    restoreLockedRows,
    stepPayload,
    stepRowsFrom,
    stepSignature,
    swapSteps,
} from '@/lib/lotRoute';
import { statusLine } from '@/lib/statusLine';
import type { RouteLot, RouteStep, RouteStepRow } from '@/types/production';

/**
 * Oracle-Forms master-detail: the lot (picked through the lots LOV, one record) and its route steps as the
 * detail block. The grid is saved as a whole (order, operations, added/removed steps, skip flags); rows with
 * production or metres waiting after them are locked (the service refuses to touch them, so the grid does not
 * let the user try). A template can replace the route of this lot or of every open lot of its PO.
 */
const props = defineProps<{ lot: RouteLot | null; steps: RouteStep[] }>();

function lotLabel(lot: RouteLot | null): string {
    return lot
        ? `${lot.lot_no} · ${lot.po_no} · ${lot.design ?? ''} · ${lot.colour ?? ''}`
        : '';
}

const lotId = ref<number | null>(props.lot?.id ?? null);
const lotDisplay = ref(lotLabel(props.lot));
const stepRows = ref<RouteStepRow[]>(stepRowsFrom(props.steps));
const baseline = ref(stepSignature(stepRows.value));
const detailKey = ref(0);
const form = useForm<{ steps: ReturnType<typeof stepPayload> }>({ steps: [] });
// Server errors name payload rows; blank grid rows are dropped from the payload, so they are mapped back to grid rows.
const errorRows = computed(() =>
    errorGridRowIndexes(form.errors, stepRows.value),
);

const template = ref<LovRow | null>(null);
const templateDisplay = ref('');
const apply = useForm({ route_template_id: null as number | null });

const lovOpen = ref(false);
const lovTargetRow = ref<number | null>(null);

function isDirty(): boolean {
    return stepSignature(stepRows.value) !== baseline.value;
}

function load(): void {
    stepRows.value = stepRowsFrom(props.steps);
    baseline.value = stepSignature(stepRows.value);
    form.clearErrors();
    detailKey.value += 1;
}

watch(() => props.steps, load);
watch(
    () => props.lot,
    (lot) => statusLine.setRecord(lot ? 1 : 0, lot ? 1 : 0),
    { immediate: true },
);
watch(
    () => isDirty(),
    (d) => statusLine.setDirty(d),
    { immediate: true },
);
watch(stepRows, (next, previous) => {
    // Ctrl+Del in the grid drops any row; a locked one goes straight back where it was.
    const restored = restoreLockedRows(previous, next);
    if (restored !== next) {
        stepRows.value = restored;
        statusLine.setMessage(
            t('production.errors.route_step_in_progress'),
            'error',
        );
    }
});

function save(): Promise<boolean> {
    return new Promise((resolve) => {
        if (!props.lot || !isDirty()) {
            statusLine.setMessage(t('forms.nothing_to_save'), 'info');
            resolve(false);
            return;
        }
        form.transform(() => ({ steps: stepPayload(stepRows.value) })).put(
            `/production/routes/${props.lot.id}`,
            {
                preserveScroll: true,
                onSuccess: () => {
                    statusLine.setMessage(t('forms.saved'), 'success');
                    resolve(true);
                },
                onError: () => {
                    statusLine.setMessage(
                        Object.values(form.errors)[0] ?? '',
                        'error',
                    );
                    detailKey.value += 1; // re-render so the row error classes apply
                    resolve(false);
                },
            },
        );
    });
}

const guard = useUnsavedGuard(isDirty, save);

let navigating = false;

function onLotSelected(row: LovRow): void {
    const id = row.id;
    guard.guard(() => {
        navigating = true;
        router.get(
            '/production/routes',
            { lot: id },
            { preserveState: false, preserveScroll: true },
        );
    });
}

watch(guard.dialogOpen, (open) => {
    // Cancel in the Save/Discard/Cancel dialog keeps the current lot: the LOV field shows it again.
    if (!open && !navigating) {
        lotId.value = props.lot?.id ?? null;
        lotDisplay.value = lotLabel(props.lot);
    }
});

function onTemplateSelected(row: LovRow): void {
    template.value = row;
    apply.clearErrors();
}

function applyTemplate(scope: 'lot' | 'po'): void {
    const lot = props.lot;
    if (!lot || apply.route_template_id === null) {
        return;
    }
    guard.guard(() => {
        const name = template.value?.label ?? '';
        const ok = window.confirm(
            scope === 'lot'
                ? t('production.route.confirm_apply_lot', {
                      lot: lot.lot_no,
                      template: name,
                  })
                : t('production.route.confirm_apply_po', {
                      po: String(lot.po_no),
                      template: name,
                  }),
        );
        if (!ok) {
            return;
        }
        apply.post(
            scope === 'lot'
                ? `/production/routes/${lot.id}/apply`
                : `/production/routes/contract/${lot.contract_id}`,
            {
                preserveScroll: true,
                onSuccess: () =>
                    statusLine.setMessage(t('forms.saved'), 'success'),
                onError: () =>
                    statusLine.setMessage(
                        Object.values(apply.errors)[0] ?? '',
                        'error',
                    ),
            },
        );
    });
}

function operationCellRenderer(
    params: ICellRendererParams<RouteStepRow>,
): HTMLElement {
    const button = document.createElement('button');
    button.type = 'button';
    button.className =
        'w-full truncate rounded border border-transparent px-2 py-0.5 text-start text-sm hover:border-input hover:bg-muted disabled:pointer-events-none disabled:opacity-100';
    button.textContent = params.value
        ? String(params.value)
        : t('forms.select');
    button.disabled = Boolean(params.data?.locked);
    button.addEventListener('click', () => {
        lovTargetRow.value = params.node?.rowIndex ?? null;
        lovOpen.value = true;
    });
    return button;
}

const tick = (p: { value: unknown }): string => (p.value ? '✓' : '');

const columns = computed<ColumnDef<RouteStepRow>[]>(() => [
    {
        colId: 'seq',
        headerName: t('masters.fields.seq'),
        width: 70,
        editable: false,
        valueGetter: (p: ValueGetterParams<RouteStepRow>) =>
            ((p.node?.rowIndex ?? 0) + 1) * 10,
    },
    {
        field: 'operation',
        headerName: t('masters.fields.operation'),
        flex: 2,
        editable: false,
        cellRenderer: operationCellRenderer,
    },
    {
        field: 'department',
        headerName: t('masters.fields.department'),
        flex: 1,
        editable: false,
    },
    {
        field: 'status',
        headerName: t('production.route.status'),
        width: 110,
        editable: false,
        valueFormatter: (p) =>
            p.value ? t(`production.step_status.${String(p.value)}`) : '',
    },
    {
        field: 'at_position',
        headerName: t('production.route.at_position'),
        width: 150,
        editable: false,
        type: 'numericColumn',
        valueFormatter: (p) => formatQty(p.value),
    },
    {
        field: 'is_added',
        headerName: t('production.route.added'),
        width: 90,
        editable: false,
        valueFormatter: tick,
    },
    {
        field: 'locked',
        headerName: t('production.route.locked'),
        width: 90,
        editable: false,
        valueFormatter: tick,
    },
    {
        field: 'skipped',
        headerName: t('production.route.skipped'),
        width: 90,
        editable: (p) => !p.data?.locked,
        cellRenderer: 'agCheckboxCellRenderer',
        cellEditor: 'agCheckboxCellEditor',
    },
]);

const rowClassRules = {
    'text-muted-foreground': (p: RowClassParams<RouteStepRow>) =>
        Boolean(p.data?.locked),
    'bg-destructive/10': (p: RowClassParams<RouteStepRow>) =>
        p.rowIndex !== undefined && errorRows.value.has(p.rowIndex),
};

function onOperationSelected(row: LovRow): void {
    const idx = lovTargetRow.value;
    lovTargetRow.value = null;
    if (idx === null || !stepRows.value[idx] || stepRows.value[idx].locked) {
        return;
    }
    const rows = [...stepRows.value];
    rows[idx] = {
        ...rows[idx],
        operation_id: Number(row.id),
        operation: String(row.label),
        department: String(row.department ?? ''),
    };
    stepRows.value = rows;
}

function onStepKeyDown(e: CellKeyDownEvent<RouteStepRow>): void {
    const ke = e.event as KeyboardEvent | undefined;
    if (!ke || !ke.ctrlKey || e.rowIndex === null || e.rowIndex === undefined) {
        return;
    }
    const target =
        ke.key === 'ArrowUp'
            ? e.rowIndex - 1
            : ke.key === 'ArrowDown'
              ? e.rowIndex + 1
              : null;
    if (
        target === null ||
        target < 0 ||
        target >= e.api.getDisplayedRowCount()
    ) {
        return;
    }
    ke.preventDefault();
    ke.stopPropagation();
    const rows: RouteStepRow[] = [];
    e.api.forEachNode((n) => n.data && rows.push(n.data));
    const swapped = swapSteps(rows, e.rowIndex, target);
    if (swapped === null) {
        statusLine.setMessage(t('production.errors.route_step_order'), 'error');
        return;
    }
    stepRows.value = swapped;
}

function onAction(action: FormsAction): void {
    switch (action) {
        case 'save':
            void save();
            break;
        case 'clear':
            // Oracle Forms' Clear prompts Save/Discard/Cancel before dropping edits.
            guard.guard(() => load());
            break;
        case 'print':
            window.print();
            break;
        case 'exit':
            router.visit('/dashboard');
            break;
    }
}
</script>

<template>
    <Head :title="t('production.route.title')" />
    <div class="flex flex-col gap-3 p-4">
        <h1 class="text-lg font-semibold">{{ t('production.route.title') }}</h1>
        <FormsToolbar
            :disabled="{
                save: !lot,
                newRecord: true,
                deleteRecord: true,
                enterQuery: true,
                executeQuery: true,
                previousRecord: true,
                nextRecord: true,
            }"
            @action="onAction"
        />
        <FormBlock :title="t('production.route.lot_block')">
            <FormField
                :label="t('production.fields.lot_no')"
                for="f-lot"
                :span="4"
                :hint="t('production.route.lot_hint')"
            >
                <LovField
                    id="f-lot"
                    v-model="lotId"
                    v-model:display="lotDisplay"
                    name="lots"
                    :filters="{ states: 'open,hold' }"
                    @selected="onLotSelected"
                />
            </FormField>
            <FormField :label="t('production.fields.po_no')" :span="2">
                <Input :model-value="lot ? String(lot.po_no) : ''" disabled />
            </FormField>
            <FormField :label="t('production.fields.party')" :span="3">
                <Input :model-value="lot?.party ?? ''" disabled />
            </FormField>
            <FormField :label="t('production.fields.quality')" :span="3">
                <Input :model-value="lot?.quality ?? ''" disabled />
            </FormField>
            <FormField :label="t('production.fields.design')" :span="3">
                <Input :model-value="lot?.design ?? ''" disabled />
            </FormField>
            <FormField :label="t('production.fields.colour')" :span="3">
                <Input :model-value="lot?.colour ?? ''" disabled />
            </FormField>
            <FormField :label="t('production.route.po_type')" :span="2">
                <Input :model-value="lot?.po_type ?? ''" disabled />
            </FormField>
            <FormField :label="t('production.route.state')" :span="2">
                <Input
                    :model-value="
                        lot ? t(`production.states.${lot.state}`) : ''
                    "
                    disabled
                />
            </FormField>
            <FormField :label="t('production.route.label')" :span="2">
                <Input :model-value="lot?.current_label ?? ''" disabled />
            </FormField>
            <FormField :label="t('production.route.stock')" :span="3">
                <Input
                    :model-value="lot ? formatQty(lot.stock_qty) : ''"
                    disabled
                    class="text-end tabular-nums"
                />
            </FormField>
            <FormField :label="t('production.route.issued')" :span="3">
                <Input
                    :model-value="lot ? formatQty(lot.issued) : ''"
                    disabled
                    class="text-end tabular-nums"
                />
            </FormField>
            <FormField :label="t('production.route.wip')" :span="3">
                <Input
                    :model-value="lot ? formatQty(lot.wip_qty) : ''"
                    disabled
                    class="text-end tabular-nums"
                />
            </FormField>
            <FormField :label="t('production.route.finished')" :span="3">
                <Input
                    :model-value="lot ? formatQty(lot.finished_qty) : ''"
                    disabled
                    class="text-end tabular-nums"
                />
            </FormField>
        </FormBlock>

        <p v-if="!lot" class="text-muted-foreground text-sm">
            {{ t('production.route.pick_lot') }}
        </p>

        <template v-else>
            <p v-if="form.errors.steps" class="text-destructive text-sm">
                {{ form.errors.steps }}
            </p>
            <div class="flex flex-col gap-1">
                <h2
                    class="text-muted-foreground text-xs font-semibold tracking-wide uppercase"
                >
                    {{ t('production.route.steps') }}
                </h2>
                <DetailGrid
                    :key="detailKey"
                    v-model:rows="stepRows"
                    :columns="columns"
                    :new-row="blankStepRow"
                    :row-class-rules="rowClassRules"
                    @cell-key-down="onStepKeyDown"
                />
                <p class="text-muted-foreground text-xs">
                    {{ t('forms.reorder_hint') }} ·
                    {{ t('production.route.locked_hint') }}
                </p>
            </div>

            <FormBlock :title="t('production.route.template')">
                <FormField
                    :label="t('production.route.template')"
                    for="f-template"
                    :span="4"
                    :error="apply.errors.route_template_id"
                    :hint="t('production.route.template_hint')"
                >
                    <LovField
                        id="f-template"
                        v-model="apply.route_template_id"
                        v-model:display="templateDisplay"
                        name="route-templates"
                        @selected="onTemplateSelected"
                    />
                </FormField>
                <FormField :label="t('masters.fields.po_type')" :span="2">
                    <Input
                        :model-value="String(template?.po_type ?? '')"
                        disabled
                    />
                </FormField>
                <FormField :label="t('masters.fields.steps')" :span="1">
                    <Input
                        :model-value="
                            template ? String(template.steps ?? '') : ''
                        "
                        disabled
                        class="text-end tabular-nums"
                    />
                </FormField>
                <div class="col-span-12 flex items-end gap-2 md:col-span-5">
                    <Button
                        type="button"
                        variant="secondary"
                        class="flex-1"
                        :disabled="
                            apply.route_template_id === null || apply.processing
                        "
                        @click="applyTemplate('lot')"
                    >
                        {{ t('production.route.apply_lot') }}
                    </Button>
                    <Button
                        type="button"
                        variant="secondary"
                        class="flex-1"
                        :disabled="
                            apply.route_template_id === null || apply.processing
                        "
                        @click="applyTemplate('po')"
                    >
                        {{ t('production.route.apply_template') }}
                    </Button>
                </div>
            </FormBlock>
        </template>

        <ConfirmSaveDialog
            :open="guard.dialogOpen.value"
            @choose="guard.choose"
        />
        <LovDialog
            v-model:open="lovOpen"
            name="operations"
            @select="onOperationSelected"
        />
    </div>
</template>
