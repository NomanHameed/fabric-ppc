<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import type { ICellRendererParams, RowClassParams } from 'ag-grid-community';
import { computed, ref, watch } from 'vue';
import ConfirmDialog from '@/components/forms/ConfirmDialog.vue';
import DataGrid from '@/components/forms/DataGrid.vue';
import FormBlock from '@/components/forms/FormBlock.vue';
import FormField from '@/components/forms/FormField.vue';
import FormsToolbar from '@/components/forms/FormsToolbar.vue';
import ReasonDialog from '@/components/forms/ReasonDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useConfirm } from '@/composables/useConfirm';
import { useReasonPrompt } from '@/composables/useReasonPrompt';
import type { FormsAction } from '@/forms/keymap';
import type { ColumnDef } from '@/lib/agGrid';
import { formatQty } from '@/lib/dates';
import { t } from '@/lib/i18n';
import { auditChanges, positionLabel, showingText } from '@/lib/inquiry';
import { statusLine } from '@/lib/statusLine';
import type {
    AuditRow,
    Capped,
    InquiryLot,
    InquiryTab,
    LineRow,
    LotSiblings,
    MovementRow,
    PositionRow,
    ReasonOption,
    TimelineStep,
} from '@/types/inquiry';

/**
 * Lot Inquiry (spec §4.4 screen 3): read-only Forms look — the lot is the record, Previous / Next walk the lots
 * of the same PO, Print and Exit work, the rest of the toolbar is off (the top-bar search is the query). The
 * summary, positions and route travel with the page; the movement, production and history tabs are optional
 * props fetched by a partial reload when opened. Hold / release / cancel post to Task 6's endpoints and are
 * shown only with `production.hold`.
 */
const props = defineProps<{
    lot: InquiryLot;
    siblings: LotSiblings;
    positions: PositionRow[];
    route: TimelineStep[];
    tab: InquiryTab;
    movements?: Capped<MovementRow>;
    lines?: Capped<LineRow>;
    audit?: Capped<AuditRow>;
    holdReasons: ReasonOption[];
    colourStatuses: ReasonOption[];
    canHold: boolean;
}>();

const tabs: InquiryTab[] = ['route', 'movements', 'lines', 'audit'];
const url = computed(() => `/inquiry/lots/${props.lot.id}`);
const stateError = ref('');
const busy = ref(false);
const prompt = useReasonPrompt();
const confirm = useConfirm();

watch(
    () => props.siblings,
    (s) => statusLine.setRecord(s.index, s.count),
    { immediate: true },
);
watch(
    () => props.lot.id,
    () => {
        stateError.value = '';
        statusLine.setMessage('', 'info');
    },
);

const stateText = computed(() => t(`production.states.${props.lot.state}`));
const reasonLabel = (code: string | null): string =>
    code === null
        ? ''
        : (props.holdReasons.find((r) => r.value === code)?.label ?? code);
const colourStatusLabel = computed(() =>
    props.lot.colour_status === null
        ? t('production.sheet.no_colour_status')
        : (props.colourStatuses.find((s) => s.value === props.lot.colour_status)
              ?.label ?? props.lot.colour_status),
);
const stateNote = computed(() => {
    const l = props.lot;
    if (l.state === 'hold') {
        return t('inquiry.lot.held', {
            at: l.state_changed_at ?? '',
            reason: reasonLabel(l.hold_reason),
        });
    }
    if (l.state === 'cancelled') {
        return t('inquiry.lot.cancelled', {
            at: l.state_changed_at ?? '',
            reason: reasonLabel(l.cancel_reason),
        });
    }
    if (l.state === 'closed') {
        return t('inquiry.lot.closed', { at: l.closed_at ?? '' });
    }
    return '';
});
const stateBadgeClass = computed(() => {
    switch (props.lot.state) {
        case 'hold':
            return 'border-amber-500/60 bg-amber-500/15 text-amber-700 dark:text-amber-400';
        case 'cancelled':
            return 'border-destructive/60 bg-destructive/10 text-destructive';
        case 'closed':
            return 'bg-muted text-muted-foreground';
        default:
            return 'border-emerald-500/60 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400';
    }
});
const activeReasons = computed(() =>
    props.holdReasons.filter((r) => r.is_active !== false),
);

function openTab(tab: InquiryTab): void {
    if (tab === props.tab) {
        return;
    }
    router.get(
        url.value,
        { tab },
        { only: [tab, 'tab'], preserveState: true, preserveScroll: true },
    );
}

function goTo(id: number | null): void {
    if (id === null) {
        return;
    }
    // A read-only page: nothing can be dirty, so the visit needs no unsaved guard.
    router.get(
        `/inquiry/lots/${id}`,
        props.tab === 'route' ? {} : { tab: props.tab },
    );
}

function onAction(action: FormsAction): void {
    switch (action) {
        case 'previousRecord':
            goTo(props.siblings.prev_id);
            break;
        case 'nextRecord':
            goTo(props.siblings.next_id);
            break;
        case 'print':
            window.print();
            break;
        case 'exit':
            router.visit('/dashboard');
            break;
    }
}

function post(path: string, data: Record<string, string>): void {
    busy.value = true;
    stateError.value = '';
    router.post(`/production/lots/${props.lot.id}/${path}`, data, {
        preserveScroll: true,
        onError: (errors) => {
            stateError.value = errors.state ?? errors.reason ?? '';
            statusLine.setMessage(stateError.value, 'error');
        },
        onFinish: () => {
            busy.value = false;
        },
    });
}

async function hold(): Promise<void> {
    const reason = await prompt.ask({
        title: t('inquiry.lot.hold_title', { lot: props.lot.lot_no }),
        label: t('inquiry.lot.reason_label'),
        options: activeReasons.value,
    });
    if (reason !== null) {
        post('hold', { reason });
    }
}

async function release(): Promise<void> {
    if (
        await confirm.ask(
            t('inquiry.lot.release_confirm', { lot: props.lot.lot_no }),
        )
    ) {
        post('release', {});
    }
}

async function cancel(): Promise<void> {
    const reason = await prompt.ask({
        title: t('inquiry.lot.cancel_title', { lot: props.lot.lot_no }),
        message: t('inquiry.lot.cancel_hint'),
        label: t('inquiry.lot.reason_label'),
        options: activeReasons.value,
    });
    if (reason !== null) {
        post('cancel', { reason });
    }
}

// ---- grids ----------------------------------------------------------------------------------------------------

const qty = (p: { value: unknown }): string => formatQty(p.value);
const tick = (p: { value: unknown }): string => (p.value ? '✓' : '');
const position = (p: { value: unknown }): string =>
    positionLabel(p.value == null ? null : String(p.value), props.route);

const routeColumns = computed<ColumnDef<TimelineStep>[]>(() => [
    {
        field: 'seq',
        headerName: t('inquiry.fields.seq'),
        width: 70,
        type: 'numericColumn',
    },
    { field: 'operation', headerName: t('inquiry.fields.operation'), flex: 2 },
    {
        field: 'department',
        headerName: t('inquiry.fields.department'),
        flex: 1,
    },
    { field: 'stage_label', headerName: t('inquiry.fields.stage'), width: 120 },
    {
        field: 'status',
        headerName: t('inquiry.fields.status'),
        width: 100,
        valueFormatter: (p) =>
            p.value ? t(`production.step_status.${String(p.value)}`) : '',
    },
    {
        field: 'from_position',
        headerName: t('inquiry.fields.from'),
        flex: 1,
        valueFormatter: position,
    },
    {
        field: 'available',
        headerName: t('inquiry.fields.waiting'),
        width: 130,
        type: 'numericColumn',
        valueFormatter: qty,
    },
    {
        field: 'to_position',
        headerName: t('inquiry.fields.to'),
        flex: 1,
        valueFormatter: position,
    },
    {
        field: 'at_position',
        headerName: t('inquiry.fields.at_position'),
        width: 150,
        type: 'numericColumn',
        valueFormatter: qty,
    },
    {
        field: 'last_done_at',
        headerName: t('inquiry.fields.last_done'),
        width: 140,
    },
    {
        field: 'is_added',
        headerName: t('inquiry.fields.added'),
        width: 80,
        valueFormatter: tick,
    },
]);

const routeRowClass = {
    'text-muted-foreground line-through': (p: RowClassParams<TimelineStep>) =>
        p.data?.status === 'skipped',
    'font-medium': (p: RowClassParams<TimelineStep>) =>
        p.data?.status === 'partial',
};

function sourceCell(p: ICellRendererParams<MovementRow>): HTMLElement {
    const row = p.data;
    const span = document.createElement('span');
    if (!row || row.source_type === null) {
        span.textContent = t('inquiry.sources.none');
        return span;
    }
    const href =
        row.source_type === 'line' && row.sheet_id !== null
            ? `/production/sheets?sheet=${row.sheet_id}`
            : row.source_type === 'import' && row.import_id !== null
              ? `/oracle/imports/${row.import_id}`
              : null;
    const text =
        row.source_type === 'line' || row.source_type === 'import'
            ? t(`inquiry.sources.${row.source_type}`)
            : row.source_type;
    if (href === null) {
        span.textContent = `${text} #${row.source_id ?? ''}`;
        return span;
    }
    const a = document.createElement('a');
    a.href = href;
    a.className = 'text-primary underline-offset-2 hover:underline';
    a.textContent = `${text} #${row.source_id ?? ''}`;
    a.addEventListener('click', (e) => {
        e.preventDefault();
        router.visit(href);
    });
    span.appendChild(a);
    return span;
}

const movementColumns = computed<ColumnDef<MovementRow>[]>(() => [
    { field: 'moved_at', headerName: t('inquiry.fields.date'), width: 140 },
    {
        field: 'kind',
        headerName: t('inquiry.fields.kind'),
        width: 130,
        valueFormatter: (p) =>
            p.value ? t(`production.kinds.${String(p.value)}`) : '',
    },
    {
        field: 'from',
        headerName: t('inquiry.fields.from'),
        flex: 1,
        valueFormatter: position,
    },
    {
        field: 'qty_out',
        headerName: t('inquiry.fields.qty_out'),
        width: 110,
        type: 'numericColumn',
        valueFormatter: qty,
    },
    {
        field: 'to',
        headerName: t('inquiry.fields.to'),
        flex: 1,
        valueFormatter: position,
    },
    {
        field: 'qty_in',
        headerName: t('inquiry.fields.qty_in'),
        width: 110,
        type: 'numericColumn',
        valueFormatter: qty,
    },
    { field: 'user', headerName: t('inquiry.fields.user'), width: 130 },
    { field: 'reason', headerName: t('inquiry.fields.reason'), flex: 2 },
    {
        colId: 'reversal',
        headerName: t('inquiry.fields.reversal'),
        width: 150,
        valueGetter: (p) =>
            p.data?.reversal_of_id != null
                ? t('inquiry.lot.reversal_of', { id: p.data.reversal_of_id })
                : p.data?.reversed_by_id != null
                  ? t('inquiry.lot.reversed_by', { id: p.data.reversed_by_id })
                  : '',
    },
    {
        colId: 'source',
        headerName: t('inquiry.fields.source'),
        width: 150,
        cellRenderer: sourceCell,
    },
]);

const movementRowClass = {
    'text-muted-foreground': (p: RowClassParams<MovementRow>) =>
        p.data?.reversal_of_id != null || p.data?.reversed_by_id != null,
};

function sheetCell(p: ICellRendererParams<LineRow>): HTMLElement {
    const a = document.createElement('a');
    const id = p.data?.sheet_id;
    a.href = `/production/sheets?sheet=${id}`;
    a.className = 'text-primary underline-offset-2 hover:underline';
    a.textContent = `${t('inquiry.lot.sheet_link')} #${id ?? ''}`;
    a.addEventListener('click', (e) => {
        e.preventDefault();
        router.visit(a.href);
    });
    return a;
}

const lineColumns = computed<ColumnDef<LineRow>[]>(() => [
    { field: 'date', headerName: t('inquiry.fields.date'), width: 110 },
    { field: 'shift', headerName: t('inquiry.fields.shift'), width: 70 },
    { field: 'machine', headerName: t('inquiry.fields.machine'), flex: 1 },
    { field: 'operation', headerName: t('inquiry.fields.operation'), flex: 2 },
    {
        field: 'step_seq',
        headerName: t('inquiry.fields.step'),
        width: 80,
        type: 'numericColumn',
    },
    { field: 'batch_no', headerName: t('inquiry.fields.batch'), width: 90 },
    {
        field: 'is_reprocess',
        headerName: t('inquiry.fields.reprocess'),
        width: 100,
        valueFormatter: tick,
    },
    {
        field: 'reprocess_reason',
        headerName: t('inquiry.fields.reprocess_reason'),
        flex: 1,
    },
    {
        field: 'qty_in',
        headerName: t('production.fields.qty_in'),
        width: 110,
        type: 'numericColumn',
        valueFormatter: qty,
    },
    {
        field: 'qty_out',
        headerName: t('production.fields.qty_out'),
        width: 110,
        type: 'numericColumn',
        valueFormatter: qty,
    },
    {
        field: 'output_type',
        headerName: t('inquiry.fields.output'),
        width: 90,
        valueFormatter: (p) =>
            p.value
                ? t(`production.sheet.output_types.${String(p.value)}`)
                : '',
    },
    {
        field: 'colour_override',
        headerName: t('inquiry.fields.colour_override'),
        flex: 1,
    },
    {
        colId: 'sheet',
        headerName: t('inquiry.fields.sheet'),
        width: 130,
        cellRenderer: sheetCell,
    },
]);

const auditColumns = computed<ColumnDef<AuditRow>[]>(() => [
    { field: 'at', headerName: t('inquiry.fields.at'), width: 140 },
    { field: 'user', headerName: t('inquiry.fields.user'), width: 130 },
    {
        field: 'event',
        headerName: t('inquiry.fields.event'),
        width: 100,
        valueFormatter: (p) =>
            p.value ? t(`inquiry.events.${String(p.value)}`) : '',
    },
    {
        field: 'model',
        headerName: t('inquiry.fields.model'),
        width: 140,
        valueFormatter: (p) =>
            p.value ? t(`inquiry.models.${String(p.value)}`) : '',
    },
    {
        field: 'record_id',
        headerName: t('inquiry.fields.record_id'),
        width: 80,
        type: 'numericColumn',
    },
    {
        colId: 'changes',
        headerName: t('inquiry.fields.changes'),
        flex: 3,
        wrapText: true,
        autoHeight: true,
        valueGetter: (p) => (p.data ? auditChanges(p.data).join('\n') : ''),
        cellClass: 'whitespace-pre-line leading-5 py-1',
    },
]);

const showing = computed(() => {
    const c = {
        movements: props.movements,
        lines: props.lines,
        audit: props.audit,
    }[props.tab as Exclude<InquiryTab, 'route'>];
    return c ? showingText(c.rows.length, c.total) : '';
});
</script>

<template>
    <Head :title="t('inquiry.lot.title', { lot: lot.lot_no })" />
    <div class="flex flex-col gap-3 p-4">
        <div class="flex flex-wrap items-center gap-2">
            <h1 class="text-lg font-semibold">
                {{ t('inquiry.lot.heading') }}
                <span class="tabular-nums">{{ lot.lot_no }}</span>
            </h1>
            <Badge
                variant="outline"
                :class="stateBadgeClass"
                data-state-badge
                >{{ stateText }}</Badge
            >
            <Badge
                v-if="lot.current_label"
                variant="secondary"
                class="font-mono"
                >{{ lot.current_label }}</Badge
            >
            <Badge variant="outline">{{
                t(`inquiry.lot.process_flag.${lot.process_flag}`)
            }}</Badge>
            <Badge variant="outline"
                >{{ t('inquiry.fields.colour_status') }}:
                {{ colourStatusLabel }}</Badge
            >
            <Link
                :href="`/inquiry/contracts/${lot.contract_id}`"
                class="text-primary ms-auto text-sm underline-offset-2 hover:underline"
                prefetch
            >
                {{ t('inquiry.lot.po_link', { po: String(lot.po_no) }) }}
            </Link>
        </div>

        <FormsToolbar
            :disabled="{
                save: true,
                newRecord: true,
                deleteRecord: true,
                enterQuery: true,
                executeQuery: true,
                clear: true,
                previousRecord: siblings.prev_id === null,
                nextRecord: siblings.next_id === null,
            }"
            @action="onAction"
        />

        <FormBlock :title="t('inquiry.lot.oracle')">
            <FormField :label="t('inquiry.fields.lot_no')" :span="2">
                <Input
                    :model-value="lot.lot_no"
                    readonly
                    class="tabular-nums"
                />
            </FormField>
            <FormField :label="t('inquiry.fields.po_no')" :span="2">
                <Input
                    :model-value="String(lot.po_no)"
                    readonly
                    class="tabular-nums"
                />
            </FormField>
            <FormField :label="t('inquiry.fields.party')" :span="4">
                <Input :model-value="lot.party" readonly />
            </FormField>
            <FormField :label="t('inquiry.fields.ref_no')" :span="2">
                <Input :model-value="lot.ref_no ?? ''" readonly />
            </FormField>
            <FormField :label="t('inquiry.fields.po_date')" :span="2">
                <Input
                    :model-value="lot.po_date"
                    readonly
                    class="tabular-nums"
                />
            </FormField>
            <FormField :label="t('inquiry.fields.quality')" :span="4">
                <Input :model-value="lot.quality" readonly />
            </FormField>
            <FormField :label="t('inquiry.fields.gsm')" :span="1">
                <Input
                    :model-value="lot.gsm === null ? '' : String(lot.gsm)"
                    readonly
                    class="text-end tabular-nums"
                />
            </FormField>
            <FormField :label="t('inquiry.fields.design')" :span="3">
                <Input :model-value="lot.design ?? ''" readonly />
            </FormField>
            <FormField :label="t('inquiry.fields.colour')" :span="2">
                <Input :model-value="lot.colour ?? ''" readonly />
            </FormField>
            <FormField :label="t('inquiry.fields.g_width')" :span="1">
                <Input
                    :model-value="
                        lot.g_width === null ? '' : formatQty(lot.g_width)
                    "
                    readonly
                    class="text-end tabular-nums"
                />
            </FormField>
            <FormField :label="t('inquiry.fields.f_width')" :span="1">
                <Input
                    :model-value="
                        lot.f_width === null ? '' : formatQty(lot.f_width)
                    "
                    readonly
                    class="text-end tabular-nums"
                />
            </FormField>
            <FormField :label="t('inquiry.fields.po_type')" :span="2">
                <Input :model-value="lot.po_type ?? ''" readonly />
            </FormField>
            <FormField :label="t('inquiry.fields.oracle_line_id')" :span="2">
                <Input
                    :model-value="String(lot.oracle_line_id)"
                    readonly
                    class="tabular-nums"
                />
            </FormField>
            <FormField :label="t('inquiry.fields.last_movement')" :span="2">
                <Input
                    :model-value="lot.last_movement_at ?? ''"
                    readonly
                    class="tabular-nums"
                />
            </FormField>
            <FormField :label="t('inquiry.fields.remarks')" :span="6">
                <Input :model-value="lot.remarks ?? ''" readonly />
            </FormField>
            <p
                v-if="lot.oracle_missing_at"
                class="text-destructive col-span-12 text-xs"
            >
                {{
                    t('inquiry.lot.missing_hint', { at: lot.oracle_missing_at })
                }}
            </p>
        </FormBlock>

        <div class="grid gap-3 lg:grid-cols-2">
            <FormBlock :title="t('inquiry.lot.quantities')">
                <FormField
                    v-for="q in [
                        'greige',
                        'stock',
                        'issued',
                        'wip',
                        'finished',
                        'dispatched',
                        'loss',
                    ] as const"
                    :key="q"
                    :label="t(`inquiry.fields.${q}`)"
                    :span="3"
                >
                    <Input
                        :model-value="formatQty(lot[`${q}_qty`])"
                        readonly
                        class="text-end tabular-nums"
                        :data-qty="q"
                    />
                </FormField>
                <div class="col-span-12">
                    <h3
                        class="text-muted-foreground mb-1 text-xs font-semibold tracking-wide uppercase"
                    >
                        {{ t('inquiry.lot.positions') }}
                    </h3>
                    <p
                        v-if="positions.length === 0"
                        class="text-muted-foreground text-sm"
                    >
                        {{ t('inquiry.lot.no_positions') }}
                    </p>
                    <table
                        v-else
                        class="w-full text-sm tabular-nums"
                        data-positions
                    >
                        <tbody>
                            <tr
                                v-for="p in positions"
                                :key="p.position"
                                class="border-t"
                            >
                                <td class="py-0.5 pe-2">
                                    {{ t(`production.positions.${p.kind}`) }}
                                    <span
                                        v-if="p.kind === 'step'"
                                        class="text-muted-foreground"
                                    >
                                        — {{ p.step_seq }}. {{ p.operation }}
                                    </span>
                                </td>
                                <td class="py-0.5 text-end">
                                    {{ formatQty(p.qty) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </FormBlock>

            <FormBlock :title="t('inquiry.lot.state_block')">
                <FormField :label="t('inquiry.fields.state')" :span="4">
                    <Input :model-value="stateText" readonly />
                </FormField>
                <FormField :label="t('inquiry.fields.label')" :span="4">
                    <Input
                        :model-value="lot.current_label ?? ''"
                        readonly
                        class="font-mono"
                    />
                </FormField>
                <FormField :label="t('inquiry.fields.process')" :span="4">
                    <Input
                        :model-value="
                            t(`inquiry.lot.process_flag.${lot.process_flag}`)
                        "
                        readonly
                    />
                </FormField>
                <p
                    v-if="stateNote"
                    class="text-muted-foreground col-span-12 text-xs"
                >
                    {{ stateNote }}
                </p>
                <p
                    v-if="stateError"
                    class="text-destructive col-span-12 text-xs"
                    data-state-error
                >
                    {{ stateError }}
                </p>
                <div v-if="canHold" class="col-span-12 flex flex-wrap gap-2">
                    <Button
                        v-if="lot.state === 'open'"
                        type="button"
                        variant="secondary"
                        size="sm"
                        :disabled="busy"
                        @click="hold"
                    >
                        {{ t('inquiry.lot.hold') }}
                    </Button>
                    <Button
                        v-if="lot.state === 'hold'"
                        type="button"
                        variant="secondary"
                        size="sm"
                        :disabled="busy"
                        @click="release"
                    >
                        {{ t('inquiry.lot.release') }}
                    </Button>
                    <Button
                        v-if="lot.state === 'open' || lot.state === 'hold'"
                        type="button"
                        variant="destructive"
                        size="sm"
                        :disabled="busy"
                        @click="cancel"
                    >
                        {{ t('inquiry.lot.cancel') }}
                    </Button>
                </div>
                <div
                    class="col-span-12 grid gap-2 border-t pt-2 md:grid-cols-2"
                >
                    <div>
                        <h3
                            class="text-muted-foreground text-xs font-semibold tracking-wide uppercase"
                        >
                            {{ t('inquiry.lot.colour_steps') }}
                        </h3>
                        <p class="text-sm">{{ colourStatusLabel }}</p>
                        <p class="text-muted-foreground text-xs">
                            {{ t('inquiry.lot.colour_steps_hint') }}
                        </p>
                    </div>
                    <div>
                        <h3
                            class="text-muted-foreground text-xs font-semibold tracking-wide uppercase"
                        >
                            {{ t('inquiry.lot.dispatches') }}
                        </h3>
                        <p class="text-sm tabular-nums">
                            {{ formatQty(lot.dispatched_qty) }}
                        </p>
                        <p class="text-muted-foreground text-xs">
                            {{ t('inquiry.lot.dispatches_hint') }}
                        </p>
                    </div>
                </div>
            </FormBlock>
        </div>

        <div role="tablist" class="flex flex-wrap items-center gap-1 border-b">
            <button
                v-for="a in tabs"
                :key="a"
                type="button"
                role="tab"
                :aria-selected="tab === a"
                class="-mb-px border-b-2 px-3 py-1.5 text-sm"
                :class="
                    tab === a
                        ? 'border-primary font-medium'
                        : 'text-muted-foreground hover:text-foreground border-transparent'
                "
                @click="openTab(a)"
            >
                {{ t(`inquiry.lot.tabs.${a}`) }}
            </button>
            <span
                v-if="showing"
                class="text-muted-foreground ms-auto text-xs tabular-nums"
                >{{ showing }}</span
            >
        </div>

        <template v-if="tab === 'route'">
            <p v-if="route.length === 0" class="text-muted-foreground text-sm">
                {{ t('inquiry.lot.no_route') }}
            </p>
            <DataGrid
                v-else
                :rows="route"
                :columns="routeColumns"
                :row-class-rules="routeRowClass"
            />
        </template>
        <DataGrid
            v-else-if="tab === 'movements'"
            :rows="movements?.rows ?? []"
            :columns="movementColumns"
            :row-class-rules="movementRowClass"
        />
        <DataGrid
            v-else-if="tab === 'lines'"
            :rows="lines?.rows ?? []"
            :columns="lineColumns"
        />
        <DataGrid
            v-else-if="tab === 'audit'"
            :rows="audit?.rows ?? []"
            :columns="auditColumns"
        />

        <p class="text-muted-foreground text-xs">
            {{ t('inquiry.lot.record_hint', { po: String(lot.po_no) }) }}
        </p>

        <ReasonDialog
            :open="prompt.open.value"
            v-bind="prompt.options.value"
            @confirm="prompt.confirm"
            @cancel="prompt.cancel"
        />
        <ConfirmDialog
            :open="confirm.open.value"
            :message="confirm.message.value"
            v-bind="confirm.options.value"
            @confirm="confirm.choose(true)"
            @cancel="confirm.choose(false)"
        />
    </div>
</template>
