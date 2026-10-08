<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import type { RowClassParams } from 'ag-grid-community';
import { computed, ref, watch } from 'vue';
import ConfirmDialog from '@/components/forms/ConfirmDialog.vue';
import ConfirmSaveDialog from '@/components/forms/ConfirmSaveDialog.vue';
import DataGrid from '@/components/forms/DataGrid.vue';
import FormBlock from '@/components/forms/FormBlock.vue';
import FormField from '@/components/forms/FormField.vue';
import FormsToolbar from '@/components/forms/FormsToolbar.vue';
import ReasonDialog from '@/components/forms/ReasonDialog.vue';
import StageProcessSelect from '@/components/stages/StageProcessSelect.vue';
import StageSelect from '@/components/stages/StageSelect.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useConfirm } from '@/composables/useConfirm';
import { useQueryMode } from '@/composables/useQueryMode';
import { useReasonPrompt } from '@/composables/useReasonPrompt';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import type { FormsAction } from '@/forms/keymap';
import type { ColumnDef } from '@/lib/agGrid';
import { formatQty } from '@/lib/dates';
import { useFlashSelect } from '@/lib/flashSelect';
import { t } from '@/lib/i18n';
import { statusLine } from '@/lib/statusLine';
import type {
    ColourStatusOption,
    FlatLotGridRow,
    FlatLotRow,
    HoldReasonOption,
    LotGridShape,
    LotScreenEdit,
    LotScreenPermissions,
    LotScreenRow,
    LotStageOverride,
} from '@/types/production';
import type { StageFallback } from '@/types/stages';

/**
 * The Lots screen (spec §4.4 screen 3, §4.2): one Oracle-Forms record per lot — a grid of the matches and a
 * single-block form for the selected one. F7 enters criteria, F8 runs them, Previous / Next walk the results,
 * Clear reverts the record (all three behind the unsaved guard). New and Delete are off: lots arrive from the
 * Oracle import and are never deleted here.
 *
 * Block 1 is Oracle's (`Lot::ORACLE_FIELDS`) and block 3 the ledger's, both read-only. Block 2 is the only
 * editable one: `colour_status` with `colour.edit`, `remarks` with `production.route.manage`; Hold / Release /
 * Cancel post to Task 6's endpoints with `production.hold`.
 *
 * The reporting stage and process sit beside the status in block 2 and are **read-only**: the ledger derives
 * them from the status, so typing over them would be typing over a calculation. PPC
 * (`production.route.manage`) can nevertheless pin them by hand with "Override stage" — the two selects then
 * become editable until the override is saved or cancelled — and "Clear override" hands the lot back to the
 * ledger, which re-derives the stage on the spot. An overridden lot is badged, with who pinned it and when,
 * so a figure a human chose never reads as one the system worked out.
 */
const props = defineProps<{
    lots: LotScreenRow[];
    lotsTotal: number;
    colourStatuses: ColourStatusOption[];
    holdReasons: HoldReasonOption[];
    can: LotScreenPermissions;
    criteria: Record<string, string>;
    /**
     * Which columns the grid shows. The form blocks always read `lots`, whatever this is: the flat shape is
     * an extra way to read the same records, never a replacement for them.
     */
    shape?: LotGridShape;
    /** The flat Oracle-shaped payload — sent with `?shape=flat`, an optional prop otherwise. */
    flatLots?: FlatLotRow[];
}>();

const CRITERIA_FIELDS = [
    'lot_no',
    'po_no',
    'party',
    'quality',
    'design',
    'colour',
    'colour_status',
    'po_type',
    'state',
    'current_label',
    'stage',
    'stage_process',
    'po_date',
] as const;

const currentId = ref<number | null>(props.lots[0]?.id ?? null);
const current = computed<LotScreenRow | null>(
    () => props.lots.find((l) => l.id === currentId.value) ?? null,
);
const index = computed(() =>
    props.lots.findIndex((l) => l.id === currentId.value),
);
const editable = computed(() => props.can.colour || props.can.remarks);
const showing = computed(() =>
    props.lots.length < props.lotsTotal
        ? t('production.lots.showing', {
              n: props.lots.length,
              m: props.lotsTotal,
          })
        : '',
);

const blank = (): LotScreenEdit => ({ colour_status: null, remarks: null });
const edits = (lot: LotScreenRow | null): LotScreenEdit =>
    lot === null
        ? blank()
        : { colour_status: lot.colour_status, remarks: lot.remarks };

const form = useForm<LotScreenEdit>(edits(current.value));
const query = useQueryMode<Record<string, string | null>>([...CRITERIA_FIELDS]);
const queryCriteria = query.criteria; // the boxes in query mode; `props.criteria` is what the server ran
const inQuery = computed(() => query.mode.value === 'enterQuery');
// With nothing to narrow on, the server shows the open work only — say so, or the screen looks like it lost rows.
const hasCriteria = computed(() =>
    Object.values(props.criteria).some((v) => String(v).trim() !== ''),
);
const confirm = useConfirm();
const prompt = useReasonPrompt();
const flashSelect = useFlashSelect();
const stateError = ref('');
const busy = ref(false);

// ---- the stage override ------------------------------------------------------------------------------------
// Its own form, posted to its own endpoint: the stage is never part of saving colour status or remarks.
const overriding = ref(false);
const stageError = ref('');
const stageForm = useForm<LotStageOverride>({
    stage_id: null,
    stage_process_id: null,
});

/** The lot's own stage, so a stage deactivated since it was pinned can still be named in the select. */
const stageFallback = computed<StageFallback>(() => {
    const lot = current.value;
    return lot === null || lot.stage_id === null || lot.stage === null
        ? null
        : { id: lot.stage_id, name: lot.stage };
});
const stageProcessFallback = computed<StageFallback>(() => {
    const lot = current.value;
    return lot === null ||
        lot.stage_process_id === null ||
        lot.stage_process === null
        ? null
        : { id: lot.stage_process_id, name: lot.stage_process };
});

/** Who pinned the stage, and when — never left implicit. */
const stageOverrideNote = computed(() => {
    const lot = current.value;
    return lot === null || !lot.stage_overridden
        ? ''
        : t('production.lots.stage_overridden_note', {
              user: lot.stage_overridden_by ?? '',
              at: lot.stage_overridden_at ?? '',
          });
});

function startOverride(): void {
    const lot = current.value;
    if (lot === null) {
        return;
    }
    stageForm.defaults({
        stage_id: lot.stage_id,
        stage_process_id: lot.stage_process_id,
    });
    stageForm.reset();
    stageForm.clearErrors();
    stageError.value = '';
    overriding.value = true;
}

function cancelOverride(): void {
    overriding.value = false;
    stageError.value = '';
    stageForm.clearErrors();
}

function saveOverride(): void {
    if (currentId.value === null) {
        return;
    }
    busy.value = true;
    stageError.value = '';
    stageForm.put(`/production/lots/${currentId.value}/stage`, {
        preserveScroll: true,
        onSuccess: () => {
            overriding.value = false;
            statusLine.setMessage(
                t('production.lots.stage_overridden_done'),
                'success',
            );
        },
        onError: (errors) => {
            stageError.value = Object.values(errors)[0] ?? '';
            statusLine.setMessage(stageError.value, 'error');
        },
        onFinish: () => {
            busy.value = false;
        },
    });
}

function clearOverride(): void {
    if (currentId.value === null) {
        return;
    }
    busy.value = true;
    stageError.value = '';
    router.delete(`/production/lots/${currentId.value}/stage`, {
        preserveScroll: true,
        onSuccess: () =>
            statusLine.setMessage(
                t('production.lots.stage_override_cleared'),
                'success',
            ),
        onError: (errors) => {
            stageError.value = Object.values(errors)[0] ?? '';
            statusLine.setMessage(stageError.value, 'error');
        },
        onFinish: () => {
            busy.value = false;
        },
    });
}

function load(id: number | null): void {
    currentId.value = id;
    form.defaults(edits(props.lots.find((l) => l.id === id) ?? null));
    form.reset();
    form.clearErrors();
    stateError.value = '';
    // A stage override being edited belongs to the record that was on screen, not to the next one.
    overriding.value = false;
    stageError.value = '';
}

watch(
    [index, () => props.lots.length],
    ([i, n]) => statusLine.setRecord(i + 1, n),
    { immediate: true },
);
watch(
    () => form.isDirty,
    (d) => statusLine.setDirty(d),
    { immediate: true },
);
watch(
    [() => props.lots, flashSelect.pending],
    ([, pending], [, wasPending]) => {
        // the just-saved lot (flashed selectedId) wins once it is among the rows; a flash whose lot the new
        // rows do not hold is stale when the rows changed on their own
        const wanted =
            flashSelect.take((id) => props.lots.some((l) => l.id === id), {
                dropIfMissing: pending === wasPending,
            }) ?? currentId.value;
        load(
            props.lots.find((l) => l.id === wanted)?.id ??
                props.lots[0]?.id ??
                null,
        );
    },
    { immediate: true },
);

// ---- save & state actions ---------------------------------------------------------------------------------

function save(): Promise<boolean> {
    return new Promise((resolve) => {
        if (currentId.value === null || !editable.value) {
            resolve(true);
            return;
        }
        form.put(`/production/lots/${currentId.value}`, {
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
                resolve(false);
            },
        });
    });
}

const guard = useUnsavedGuard(() => form.isDirty, save);

function post(path: string, data: Record<string, string>): void {
    if (currentId.value === null) {
        return;
    }
    busy.value = true;
    stateError.value = '';
    router.post(`/production/lots/${currentId.value}/${path}`, data, {
        preserveScroll: true,
        onError: (errors) => {
            stateError.value =
                errors.state ?? errors.reason ?? Object.values(errors)[0] ?? '';
            form.setError(
                errors as Partial<Record<keyof LotScreenEdit, string>>,
            );
            statusLine.setMessage(stateError.value, 'error');
        },
        onFinish: () => {
            busy.value = false;
        },
    });
}

async function hold(): Promise<void> {
    const lot = current.value;
    if (lot === null) {
        return;
    }
    const reason = await prompt.ask({
        title: t('production.lots.hold_title', { lot: lot.lot_no }),
        label: t('production.lots.reason_label'),
        options: props.holdReasons,
    });
    if (reason !== null) {
        post('hold', { reason });
    }
}

async function release(): Promise<void> {
    const lot = current.value;
    if (
        lot !== null &&
        (await confirm.ask(
            t('production.lots.release_confirm', { lot: lot.lot_no }),
        ))
    ) {
        post('release', {});
    }
}

async function cancel(): Promise<void> {
    const lot = current.value;
    if (lot === null) {
        return;
    }
    const reason = await prompt.ask({
        title: t('production.lots.cancel_title', { lot: lot.lot_no }),
        message: t('production.lots.cancel_hint'),
        label: t('production.lots.reason_label'),
        options: props.holdReasons,
    });
    if (reason !== null) {
        post('cancel', { reason });
    }
}

// ---- toolbar ----------------------------------------------------------------------------------------------

function executeQuery(): void {
    guard.guard(() => {
        const q = inQuery.value ? query.takeCriteria() : {};
        router.get(
            '/production/lots',
            { q, shape: gridShape.value },
            {
                only: ['lots', 'lotsTotal', 'flatLots', 'shape', 'criteria'],
                preserveState: true,
                preserveScroll: true,
                // a criterion the server rejected must tell the user, not silently drop query mode
                onError: (errors) => {
                    statusLine.setMessage(
                        Object.values(errors)[0] ?? '',
                        'error',
                    );
                    query.enterQuery();
                },
            },
        );
    });
}

function onAction(action: FormsAction): void {
    switch (action) {
        case 'save':
            void save();
            break;
        case 'enterQuery':
            query.enterQuery();
            break;
        case 'executeQuery':
            executeQuery();
            break;
        case 'previousRecord':
            if (index.value > 0) {
                guard.guard(() => load(props.lots[index.value - 1].id));
            }
            break;
        case 'nextRecord':
            if (index.value < props.lots.length - 1) {
                guard.guard(() => load(props.lots[index.value + 1].id));
            }
            break;
        case 'clear':
            if (inQuery.value) {
                query.cancelQuery();
            } else {
                // Oracle Forms' Clear prompts Save/Discard/Cancel before dropping edits.
                guard.guard(() => load(currentId.value));
            }
            break;
        case 'print':
            window.print();
            break;
        case 'exit':
            router.visit('/dashboard');
            break;
    }
}

// ---- display ----------------------------------------------------------------------------------------------

const stateBadgeClass = computed(() => {
    switch (current.value?.state) {
        case 'hold':
            return 'border-warning/60 bg-warning-surface text-warning-on-surface';
        case 'cancelled':
            return 'border-destructive/60 bg-destructive-surface text-destructive-on-surface';
        case 'closed':
            return 'bg-muted text-muted-foreground';
        default:
            return 'border-success/60 bg-success-surface text-success-on-surface';
    }
});

/** Why a held or cancelled lot is in that state, and since when (the Lot Inquiry shows the same note). */
const stateNote = computed(() => {
    const lot = current.value;
    if (lot === null || (lot.state !== 'hold' && lot.state !== 'cancelled')) {
        return '';
    }
    const code = lot.state === 'hold' ? lot.hold_reason : lot.cancel_reason;
    return t('production.lots.state_reason', {
        reason:
            code === null
                ? ''
                : (props.holdReasons.find((r) => r.value === code)?.label ??
                  code),
        at: lot.state_changed_at ?? '',
    });
});

const colourStatusOptions = computed(() =>
    props.colourStatuses.map((s) => ({
        value: s.value,
        label: s.approved
            ? `${s.label} ✓ (${t('production.lots.approved')})`
            : s.label,
    })),
);

const qty = (p: { value: unknown }): string => formatQty(p.value);
const qtyColumn = (field: keyof LotScreenRow, key: string) => ({
    field,
    headerName: t(`production.fields.${key}`),
    width: 120,
    type: 'numericColumn',
    valueFormatter: qty,
});

const columns = computed<ColumnDef<LotScreenRow>[]>(() => [
    {
        field: 'lot_no',
        headerName: t('production.fields.lot_no'),
        width: 100,
        pinned: 'left',
    },
    {
        field: 'po_no',
        headerName: t('production.fields.po_no'),
        width: 100,
        type: 'numericColumn',
    },
    { field: 'party', headerName: t('production.fields.party'), flex: 2 },
    { field: 'design', headerName: t('production.fields.design'), flex: 1 },
    { field: 'colour', headerName: t('production.fields.colour'), flex: 1 },
    {
        field: 'colour_status',
        headerName: t('production.fields.colour_status'),
        width: 130,
    },
    {
        field: 'po_type',
        headerName: t('production.fields.po_type'),
        width: 110,
    },
    {
        field: 'po_date',
        headerName: t('production.fields.po_date'),
        width: 110,
        cellClass: 'tabular-nums',
    },
    { field: 'quality', headerName: t('production.fields.quality'), flex: 2 },
    {
        field: 'current_label',
        headerName: t('production.fields.label'),
        width: 130,
        cellClass: 'font-mono',
    },
    // The reporting taxonomy beside the raw status, never instead of it.
    {
        field: 'stage',
        headerName: t('production.fields.stage'),
        width: 150,
        valueFormatter: (p) =>
            p.data?.stage_overridden
                ? `${String(p.value ?? '')} *`
                : String(p.value ?? ''),
    },
    {
        field: 'stage_process',
        headerName: t('production.fields.stage_process'),
        width: 170,
    },
    qtyColumn('greige_qty', 'greige'),
    qtyColumn('stock_qty', 'stock'),
    qtyColumn('issued_qty', 'issued'),
    qtyColumn('wip_qty', 'wip'),
    qtyColumn('finished_qty', 'finished'),
    qtyColumn('dispatched_qty', 'dispatched'),
    {
        field: 'state',
        headerName: t('production.fields.state'),
        width: 100,
        valueFormatter: (p) =>
            p.value ? t(`production.states.${String(p.value)}`) : '',
    },
]);

const rowClassRules = {
    'text-muted-foreground': (p: RowClassParams<LotScreenRow>) =>
        p.data !== undefined &&
        (p.data.state === 'cancelled' || p.data.state === 'closed'),
};

// ---- the flat, Oracle-shaped grid --------------------------------------------------------------------------
// The same lots under the mill's own field names (ProductionAnalyticsService::flatLots). Only the grid changes:
// the record form, the stage override and every state action still read `lots`.

const gridShape = computed<LotGridShape>(() => props.shape ?? 'nested');

/** `DataGrid` keys its nodes by `id`; the payload's primary key is `lot_id`, so it is copied over here. */
const flatRows = computed<FlatLotGridRow[]>(() =>
    (props.flatLots ?? []).map((r) => ({ ...r, id: r.lot_id })),
);

/** Fixed 2-dp metres, but a null stays blank: an unmeasured width is not a width of zero. */
const flatQty = (p: { value: unknown }): string =>
    p.value === null || p.value === undefined ? '' : formatQty(p.value);

/** A metres column: right-aligned, tabular figures, 2 dp. */
const flatQtyColumn = (field: keyof FlatLotGridRow) => ({
    field,
    headerName: t(`production.flat.${field}`),
    width: 140,
    type: 'numericColumn',
    cellClass: 'text-end tabular-nums',
    valueFormatter: flatQty,
});

/** A whole-number identifier column (PO number, party code): right-aligned and tabular, but not metres. */
const flatIdColumn = (field: keyof FlatLotGridRow, width: number) => ({
    field,
    headerName: t(`production.flat.${field}`),
    width,
    type: 'numericColumn',
    cellClass: 'text-end tabular-nums',
});

/** A plain text column. */
const flatTextColumn = (
    field: keyof FlatLotGridRow,
    width: number,
    cellClass?: string,
) => ({
    field,
    headerName: t(`production.flat.${field}`),
    width,
    ...(cellClass === undefined ? {} : { cellClass }),
});

/**
 * The flat grid's columns. The payload's own order, with one deliberate move: `status_oracle` sits beside
 * `stage_name` / `stage_process_name`, because the raw status and the reporting stage it resolves to are read
 * together — the stage never replaces the status. The three id columns the payload also carries (`stage_id`,
 * `stage_process_id`, `lot_id`) are not shown: they are keys, not figures.
 */
const flatColumns = computed<ColumnDef<FlatLotGridRow>[]>(() => [
    {
        ...flatTextColumn('lot_no_oracle', 120, 'tabular-nums'),
        pinned: 'left',
    },
    flatIdColumn('po_no', 100),
    flatTextColumn('ref', 110),
    flatIdColumn('party_oracle_id', 110),
    {
        field: 'party_oracle_name',
        headerName: t('production.flat.party_oracle_name'),
        flex: 2,
        minWidth: 160,
    },
    flatTextColumn('date_oracle', 110, 'tabular-nums'),
    flatTextColumn('design_oracle_id', 120),
    flatTextColumn('color_oracle_id', 120),
    {
        field: 'color_oracle_name',
        headerName: t('production.flat.color_oracle_name'),
        flex: 1,
        minWidth: 140,
    },
    flatTextColumn('clr_status_oracle', 130),
    flatTextColumn('po_type_oracle', 110),
    {
        field: 'quality',
        headerName: t('production.flat.quality'),
        flex: 2,
        minWidth: 180,
    },
    {
        field: 'gw_oracle',
        headerName: t('production.flat.gw_oracle'),
        width: 90,
        type: 'numericColumn',
        cellClass: 'text-end tabular-nums',
        valueFormatter: flatQty,
    },
    flatTextColumn('status_oracle', 130, 'font-mono'),
    flatTextColumn('stage_name', 150),
    flatTextColumn('stage_process_name', 170),
    flatQtyColumn('greige_received_oracle'),
    flatQtyColumn('greige_in_stock_oracle'),
    flatQtyColumn('qty_issued'),
    flatQtyColumn('wip'),
    flatQtyColumn('finished'),
    flatQtyColumn('dispatch'),
]);

/**
 * Swap the grid's columns. A server round trip rather than a client-side remap, because the flat shape is
 * assembled in SQL — and behind the unsaved guard, as every other in-page navigation on this screen is.
 */
function switchShape(shape: LotGridShape): void {
    if (shape === gridShape.value) {
        return;
    }
    guard.guard(() => {
        router.get(
            '/production/lots',
            { q: props.criteria, shape },
            {
                only: ['lots', 'lotsTotal', 'flatLots', 'shape', 'criteria'],
                preserveState: true,
                preserveScroll: true,
            },
        );
    });
}
</script>

<template>
    <Head :title="t('production.lots.title')" />
    <div class="flex min-w-0 flex-col gap-3 p-3 sm:p-4">
        <div class="flex flex-wrap items-center gap-2">
            <h1 class="text-lg font-semibold">
                {{ t('production.lots.title') }}
            </h1>
            <div class="ms-auto flex flex-wrap items-center gap-2">
                <Badge
                    v-if="!hasCriteria"
                    variant="outline"
                    class="text-muted-foreground text-xs font-normal"
                    >{{ t('production.lots.default_query') }}</Badge
                >
                <span
                    v-if="showing"
                    class="text-muted-foreground text-xs tabular-nums"
                    >{{ showing }}</span
                >
            </div>
        </div>
        <FormsToolbar
            :mode="query.mode.value"
            :disabled="{
                save: !editable || inQuery || currentId === null,
                newRecord: true,
                deleteRecord: true,
                previousRecord: index <= 0,
                nextRecord: index >= lots.length - 1,
            }"
            @action="onAction"
        />

        <!-- criteria (query mode) -->
        <FormBlock v-if="inQuery" :title="t('forms.query_mode')">
            <FormField
                v-for="f in CRITERIA_FIELDS"
                :key="f"
                :label="t(`production.fields.${f}`)"
                :for="`q-${f}`"
                :span="3"
                :hint="t('production.lots.query_hint')"
            >
                <Input
                    :id="`q-${f}`"
                    v-model="queryCriteria[f] as string"
                    class="border-warning/70"
                    autocomplete="off"
                />
            </FormField>
        </FormBlock>

        <template v-else-if="current">
            <!-- block 1: Oracle's, read-only -->
            <FormBlock :title="t('production.lots.oracle')">
                <FormField :label="t('production.fields.lot_no')" :span="2">
                    <Input
                        :model-value="current.lot_no"
                        readonly
                        class="tabular-nums"
                    />
                </FormField>
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
                <FormField :label="t('production.fields.quality')" :span="5">
                    <Input :model-value="current.quality" readonly />
                </FormField>
                <FormField :label="t('production.fields.gsm')" :span="1">
                    <Input
                        :model-value="
                            current.gsm === null ? '' : String(current.gsm)
                        "
                        readonly
                        class="text-end tabular-nums"
                    />
                </FormField>
                <FormField :label="t('production.fields.design')" :span="2">
                    <Input :model-value="current.design ?? ''" readonly />
                </FormField>
                <FormField :label="t('production.fields.colour')" :span="2">
                    <Input :model-value="current.colour ?? ''" readonly />
                </FormField>
                <FormField :label="t('production.fields.g_width')" :span="1">
                    <Input
                        :model-value="
                            current.g_width === null
                                ? ''
                                : formatQty(current.g_width)
                        "
                        readonly
                        class="text-end tabular-nums"
                    />
                </FormField>
                <FormField :label="t('production.fields.f_width')" :span="1">
                    <Input
                        :model-value="
                            current.f_width === null
                                ? ''
                                : formatQty(current.f_width)
                        "
                        readonly
                        class="text-end tabular-nums"
                    />
                </FormField>
                <FormField :label="t('production.fields.po_type')" :span="2">
                    <Input :model-value="current.po_type" readonly />
                </FormField>
                <p class="text-muted-foreground col-span-12 text-xs">
                    {{ t('production.lots.oracle_hint') }}
                </p>
                <p
                    v-if="current.oracle_missing_at"
                    class="text-destructive col-span-12 text-xs"
                >
                    {{
                        t('production.lots.missing', {
                            date: current.oracle_missing_at,
                        })
                    }}
                </p>
            </FormBlock>

            <!-- block 2: the app's, editable per permission -->
            <FormBlock :title="t('production.lots.app')">
                <FormField
                    :label="t('production.fields.colour_status')"
                    for="colour-status"
                    :span="3"
                    :error="form.errors.colour_status"
                >
                    <select
                        id="colour-status"
                        v-model="form.colour_status"
                        :disabled="!can.colour"
                        class="bg-background h-9 rounded-md border px-2 text-sm"
                    >
                        <option :value="null">
                            {{ t('production.lots.no_colour_status') }}
                        </option>
                        <option
                            v-for="o in colourStatusOptions"
                            :key="o.value"
                            :value="o.value"
                        >
                            {{ o.label }}
                        </option>
                    </select>
                </FormField>
                <FormField
                    :label="t('production.fields.state')"
                    :span="3"
                    :error="stateError"
                >
                    <div class="flex min-h-9 flex-col justify-center gap-0.5">
                        <Badge
                            variant="outline"
                            :class="stateBadgeClass"
                            class="w-fit"
                            >{{
                                t(`production.states.${current.state}`)
                            }}</Badge
                        >
                        <span
                            v-if="stateNote"
                            class="text-muted-foreground text-xs"
                            >{{ stateNote }}</span
                        >
                    </div>
                </FormField>
                <FormField :label="t('production.fields.label')" :span="2">
                    <Input
                        :model-value="current.current_label ?? ''"
                        readonly
                        class="font-mono"
                    />
                </FormField>
                <FormField :label="t('production.fields.process')" :span="2">
                    <div class="flex h-9 items-center">
                        <Badge variant="outline">{{
                            t(`production.lots.process.${current.process_flag}`)
                        }}</Badge>
                    </div>
                </FormField>
                <FormField
                    :label="t('production.fields.last_movement')"
                    :span="2"
                >
                    <Input
                        :model-value="current.last_movement_at ?? ''"
                        readonly
                        class="tabular-nums"
                    />
                </FormField>

                <!-- the reporting stage: derived from the status, or pinned by hand -->
                <FormField
                    :label="t('production.fields.stage')"
                    for="lot-stage"
                    :span="3"
                    :error="stageForm.errors.stage_id"
                >
                    <StageSelect
                        id="lot-stage"
                        v-model="stageForm.stage_id"
                        :disabled="!overriding"
                        :fallback="stageFallback"
                    />
                </FormField>
                <FormField
                    :label="t('production.fields.stage_process')"
                    for="lot-stage-process"
                    :span="3"
                    :error="stageForm.errors.stage_process_id"
                >
                    <StageProcessSelect
                        id="lot-stage-process"
                        v-model="stageForm.stage_process_id"
                        :stage-id="stageForm.stage_id"
                        :disabled="!overriding"
                        :fallback="stageProcessFallback"
                    />
                </FormField>
                <FormField
                    :label="t('production.lots.stage_block')"
                    :span="6"
                    :error="stageError"
                >
                    <div class="flex min-h-9 flex-wrap items-center gap-2">
                        <Badge
                            v-if="current.stage_overridden"
                            variant="outline"
                            class="border-warning/60 bg-warning-surface text-warning-on-surface"
                            >{{ t('production.lots.stage_overridden') }}</Badge
                        >
                        <template v-if="can.stage">
                            <Button
                                v-if="!overriding"
                                type="button"
                                variant="outline"
                                size="sm"
                                :disabled="busy"
                                @click="guard.guard(() => startOverride())"
                                >{{
                                    t('production.lots.stage_override')
                                }}</Button
                            >
                            <template v-else>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    :disabled="
                                        busy ||
                                        stageForm.stage_id === null ||
                                        stageForm.stage_process_id === null
                                    "
                                    @click="saveOverride()"
                                    >{{
                                        t('production.lots.stage_override_save')
                                    }}</Button
                                >
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    :disabled="busy"
                                    @click="cancelOverride()"
                                    >{{
                                        t(
                                            'production.lots.stage_override_cancel',
                                        )
                                    }}</Button
                                >
                            </template>
                            <Button
                                v-if="current.stage_overridden && !overriding"
                                type="button"
                                variant="outline"
                                size="sm"
                                :disabled="busy"
                                @click="guard.guard(() => clearOverride())"
                                >{{
                                    t('production.lots.stage_override_clear')
                                }}</Button
                            >
                        </template>
                    </div>
                </FormField>
                <p class="text-muted-foreground col-span-12 text-xs">
                    {{
                        stageOverrideNote !== ''
                            ? stageOverrideNote
                            : current.stage_process_id === null
                              ? t('production.lots.stage_none')
                              : t('production.lots.stage_derived')
                    }}
                    <template v-if="overriding">
                        {{ t('production.lots.stage_override_hint') }}
                    </template>
                </p>
                <FormField
                    :label="t('production.fields.remarks')"
                    for="lot-remarks"
                    :span="12"
                    :error="form.errors.remarks"
                >
                    <textarea
                        id="lot-remarks"
                        v-model="form.remarks"
                        :disabled="!can.remarks"
                        rows="2"
                        class="bg-background min-h-16 rounded-md border px-2 py-1 text-sm"
                    ></textarea>
                </FormField>
                <div class="col-span-12 flex flex-wrap items-center gap-2">
                    <template v-if="can.hold">
                        <Button
                            v-if="current.state === 'open'"
                            type="button"
                            variant="outline"
                            size="sm"
                            :disabled="busy"
                            @click="guard.guard(() => void hold())"
                            >{{ t('production.lots.hold') }}</Button
                        >
                        <Button
                            v-if="current.state === 'hold'"
                            type="button"
                            variant="outline"
                            size="sm"
                            :disabled="busy"
                            @click="guard.guard(() => void release())"
                            >{{ t('production.lots.release') }}</Button
                        >
                        <Button
                            v-if="
                                current.state === 'open' ||
                                current.state === 'hold'
                            "
                            type="button"
                            variant="outline"
                            size="sm"
                            :disabled="busy"
                            @click="guard.guard(() => void cancel())"
                            >{{ t('production.lots.cancel') }}</Button
                        >
                    </template>
                    <Link
                        class="text-primary ms-auto text-sm underline"
                        :href="`/inquiry/lots/${current.id}`"
                        >{{ t('production.lots.inquiry') }}</Link
                    >
                    <Link
                        class="text-primary text-sm underline"
                        :href="`/inquiry/contracts/${current.contract_id}`"
                        >{{ t('production.lots.po_inquiry') }}</Link
                    >
                    <Link
                        v-if="can.route"
                        class="text-primary text-sm underline"
                        :href="`/production/routes?lot=${current.id}`"
                        >{{ t('production.lots.route') }}</Link
                    >
                    <Link
                        class="text-primary text-sm underline"
                        :href="`/production/timeline?lot=${current.id}`"
                        >{{ t('production.lots.timeline') }}</Link
                    >
                </div>
                <p
                    v-if="!editable"
                    class="text-muted-foreground col-span-12 text-xs"
                >
                    {{ t('production.lots.read_only') }}
                </p>
            </FormBlock>

            <!-- block 3: the ledger's, read-only -->
            <FormBlock :title="t('production.lots.quantities')">
                <FormField
                    v-for="q in [
                        { key: 'greige', value: current.greige_qty },
                        { key: 'stock', value: current.stock_qty },
                        { key: 'issued', value: current.issued_qty },
                        { key: 'wip', value: current.wip_qty },
                        { key: 'finished', value: current.finished_qty },
                        { key: 'dispatched', value: current.dispatched_qty },
                        { key: 'loss', value: current.loss_qty },
                    ]"
                    :key="q.key"
                    :label="t(`production.fields.${q.key}`)"
                    :span="2"
                >
                    <Input
                        :model-value="formatQty(q.value)"
                        readonly
                        class="text-end tabular-nums"
                    />
                </FormField>
            </FormBlock>
        </template>

        <p v-else class="text-muted-foreground text-sm">
            {{ t('production.lots.none') }}
        </p>

        <!-- the app's own columns -->
        <DataGrid
            v-if="gridShape === 'nested' && lots.length > 0"
            :rows="lots"
            :columns="columns"
            :selected-id="currentId"
            :row-class-rules="rowClassRules"
            @row-selected="(r) => guard.guard(() => load(r.id))"
            @row-activated="
                (r) => guard.guard(() => router.visit(`/inquiry/lots/${r.id}`))
            "
        />

        <!-- the flat, Oracle-shaped payload: the same lots, the mill's own field names -->
        <template v-if="gridShape === 'flat'">
            <p class="text-muted-foreground text-xs">
                {{ t('production.lots.shape_flat_hint') }}
            </p>
            <DataGrid
                v-if="flatRows.length > 0"
                :rows="flatRows"
                :columns="flatColumns"
                :selected-id="currentId"
                @row-selected="(r) => guard.guard(() => load(r.lot_id))"
                @row-activated="
                    (r) =>
                        guard.guard(() =>
                            router.visit(`/inquiry/lots/${r.lot_id}`),
                        )
                "
            />
        </template>

        <ConfirmSaveDialog
            :open="guard.dialogOpen.value"
            @choose="guard.choose"
        />
        <ConfirmDialog
            :open="confirm.open.value"
            :message="confirm.message.value"
            v-bind="confirm.options.value"
            @confirm="confirm.choose(true)"
            @cancel="confirm.choose(false)"
        />
        <ReasonDialog
            :open="prompt.open.value"
            v-bind="prompt.options.value"
            @confirm="prompt.confirm"
            @cancel="prompt.cancel"
        />
    </div>
</template>
