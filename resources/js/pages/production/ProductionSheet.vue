<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import type {
    CellFocusedEvent,
    CellKeyDownEvent,
    CellValueChangedEvent,
    EditableCallbackParams,
    ICellEditorParams,
    RowClassParams,
    ValueFormatterParams,
    ValueSetterParams,
} from 'ag-grid-community';
import { computed, ref, useTemplateRef, watch } from 'vue';
import ConfirmDialog from '@/components/forms/ConfirmDialog.vue';
import ConfirmSaveDialog from '@/components/forms/ConfirmSaveDialog.vue';
import DataGrid from '@/components/forms/DataGrid.vue';
import DetailGrid from '@/components/forms/DetailGrid.vue';
import FormBlock from '@/components/forms/FormBlock.vue';
import FormField from '@/components/forms/FormField.vue';
import FormsToolbar from '@/components/forms/FormsToolbar.vue';
import LovDialog from '@/components/forms/LovDialog.vue';
import LovField from '@/components/forms/LovField.vue';
import ReasonDialog from '@/components/forms/ReasonDialog.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useConfirm } from '@/composables/useConfirm';
import { useGridLov } from '@/composables/useGridLov';
import type { GridLovCell } from '@/composables/useGridLov';
import { useQueryMode } from '@/composables/useQueryMode';
import { useReasonPrompt } from '@/composables/useReasonPrompt';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import type { FormsAction } from '@/forms/keymap';
import type { ColumnDef } from '@/lib/agGrid';
import { ApiError, getJson, postJson } from '@/lib/api';
import { can } from '@/lib/can';
import { formatQty, todayDmy } from '@/lib/dates';
import { useFlashSelect } from '@/lib/flashSelect';
import { t } from '@/lib/i18n';
import { fetchLov } from '@/lib/lov';
import type { LovRow } from '@/lib/lov';
import {
    applyLotContext,
    applyLotPick,
    applyOperationPick,
    availableFor,
    blankLineRow,
    clearOverrides,
    departmentSteps,
    incompleteRowIndexes,
    lineErrorText,
    lineErrors,
    linePayload,
    lineRowsFrom,
    lineSignature,
    overrideToAsk,
    rowByUid,
    sourcePosition,
    stepLabel,
    totalsInput,
} from '@/lib/productionSheet';
import { sheetTotals, TOTAL_BUCKETS, TOTAL_GROUPS } from '@/lib/sheetTotals';
import { statusLine } from '@/lib/statusLine';
import type {
    LotContext,
    OperationOption,
    Option,
    OutputType,
    SheetDetail,
    SheetLineRow,
    SheetListRow,
} from '@/types/production';

/**
 * Oracle-Forms master-detail (spec §4.4 screen 5, §6.3): the sheet header (date, shift, department, machine,
 * downtime, remarks) is the block's record, the lines are the detail grid, and the footer totals are computed
 * from the grid. Query mode (F7/F8) runs over the saved sheets by date/shift/department/machine; Previous/Next
 * and the list walk the results. Every record change (and Execute Query) goes through the unsaved guard. The
 * service's refusals (insufficient metres, colour gate) come back as `lines.N.<field>` errors: the row is
 * highlighted, the status line says which line, and — when the server keys an override hint for this user — a
 * reason is asked for and the save re-submitted.
 *
 * The detail grid holds copies of the rows (see DetailGrid): every change is made on the model row in `lines`,
 * found by its `_uid`, and the grid follows; after an `await` the row is looked up again, since a grid edit
 * meanwhile replaces the model objects.
 */
const props = defineProps<{
    sheets: SheetListRow[];
    selected: SheetDetail | null;
    shifts: Option[];
    departments: Option[];
    machines: Option[];
    operations: OperationOption[];
    reprocessReasons: Option[];
    canEditAll: boolean;
    canEditOwn: boolean;
    ownDepartmentIds: number[];
    criteria: Record<string, string>;
}>();

type HeaderForm = {
    sheet_date: string;
    shift_id: number | null;
    department_id: number | null;
    machine_id: number | null;
    downtime_minutes: number | string;
    remarks: string;
};

const canOverrideColour = can('colour.override.approve');

function allowedDepartment(id: number | null): boolean {
    return (
        id !== null && (props.canEditAll || props.ownDepartmentIds.includes(id))
    );
}

function blankHeader(): HeaderForm {
    return {
        sheet_date: todayDmy(),
        shift_id: (props.shifts[0]?.value as number | undefined) ?? null,
        department_id: props.canEditAll
            ? null
            : (props.ownDepartmentIds[0] ?? null),
        machine_id: null,
        downtime_minutes: 0,
        remarks: '',
    };
}

function headerFrom(s: SheetDetail): HeaderForm {
    return {
        sheet_date: s.sheet_date,
        shift_id: s.shift_id,
        department_id: s.department_id,
        machine_id: s.machine_id,
        downtime_minutes: s.downtime_minutes,
        remarks: s.remarks ?? '',
    };
}

function machineLabel(id: number | null): string {
    const m = props.machines.find((o) => o.value === id);
    return m ? (m.code === m.label ? m.label : `${m.code} — ${m.label}`) : '';
}

function operationById(id: number | null): OperationOption | undefined {
    return props.operations.find((o) => o.value === id);
}

/** The operation a LOV pick stands for: the page's option (kind, stretch, finish type), or the LOV row's own columns. */
function operationFromPick(picked: LovRow): OperationOption {
    return (
        operationById(picked.id) ?? {
            value: picked.id,
            label: String(picked.label),
            department_id: Number(picked.department_id ?? 0),
            kind: String(picked.kind ?? 'process'),
            is_stretch: Boolean(picked.is_stretch),
            finish_type:
                (picked.finish_type as OutputType | null | undefined) ?? null,
        }
    );
}

// ---- state --------------------------------------------------------------------------------------------------

const currentId = ref<number | null>(props.selected?.id ?? null);
const form = useForm<HeaderForm>(
    props.selected ? headerFrom(props.selected) : blankHeader(),
);
const machineDisplay = ref(machineLabel(form.machine_id));
const lines = ref<SheetLineRow[]>(
    props.selected ? lineRowsFrom(props.selected.lines) : [],
);
const linesBaseline = ref(lineSignature(lines.value));
const rowErrors = ref<Set<number>>(new Set());
const grid = useTemplateRef<{
    stopEditing: (cancel?: boolean) => void;
    redrawRows: () => void;
}>('grid');
const contexts = ref<Record<number, LotContext>>({});
const inFlight = new Map<number, Promise<LotContext>>();

const query = useQueryMode<Record<string, string | null>>([
    'sheet_date',
    'shift',
    'department',
    'machine',
]);
const criteria = query.criteria;
const inQuery = computed(() => query.mode.value === 'enterQuery');
const lov = useGridLov<SheetLineRow>();
const confirm = useConfirm();
const prompt = useReasonPrompt();
const flashSelect = useFlashSelect();

let filling = false;
let submitting = false;
let expecting: number | null = null; // the sheet id a load() asked the server for
let queryPending = false;

const index = computed(() =>
    props.sheets.findIndex((s) => s.id === currentId.value),
);
/** Whether the record on screen may be saved (also from the unsaved guard while query mode hides it). */
const canEditRecord = computed(() => {
    if (currentId.value === null) {
        return props.canEditAll || props.ownDepartmentIds.length > 0;
    }
    return props.selected?.id === currentId.value
        ? props.selected.can_edit
        : false;
});
const canEditSheet = computed(() => !inQuery.value && canEditRecord.value);
const totals = computed(() =>
    sheetTotals(totalsInput(lines.value, props.operations)),
);
// errors keyed outside the header fields (`lines`, `lines.N.*`) are not part of the form's own type
const linesError = computed(
    () => (form.errors as Record<string, string | undefined>)['lines'],
);

function isDirty(): boolean {
    return form.isDirty || lineSignature(lines.value) !== linesBaseline.value;
}

/** Row error highlights changed: the grid's row class rules are evaluated again. */
function showRowErrors(rows: Iterable<number>): void {
    rowErrors.value = new Set(rows);
    grid.value?.redrawRows();
}

// ---- loading ------------------------------------------------------------------------------------------------

function fill(selected: SheetDetail | null): void {
    filling = true;
    form.defaults(selected ? headerFrom(selected) : blankHeader());
    form.reset();
    form.clearErrors();
    filling = false;
    machineDisplay.value = machineLabel(form.machine_id);
    lines.value = selected ? lineRowsFrom(selected.lines) : []; // all new uids: the grid rebuilds its rows
    linesBaseline.value = lineSignature(lines.value);
    rowErrors.value = new Set();
    contexts.value = {};
    statusLine.setHint('');
    const viewer = !props.canEditAll && !props.canEditOwn;
    if (selected && !selected.can_edit) {
        statusLine.setMessage(
            t(
                viewer
                    ? 'production.sheet.read_only'
                    : 'production.sheet.other_department',
            ),
            'info',
        );
    } else if (!selected && viewer) {
        statusLine.setMessage(t('production.sheet.read_only'), 'info');
    } else if (!selected && !canEditRecord.value) {
        statusLine.setMessage(t('production.sheet.no_department'), 'error');
    } else {
        statusLine.setMessage('', 'info');
    }
    void prefetchContexts();
}

function load(id: number | null): void {
    currentId.value = id;
    if (id === null) {
        expecting = null;
        fill(null);
        return;
    }
    if (props.selected?.id === id) {
        expecting = null;
        fill(props.selected);
        return;
    }
    expecting = id;
    router.get(
        '/production/sheets',
        { sheet: id, q: props.criteria },
        { only: ['selected'], preserveState: true, preserveScroll: true },
    );
}

watch(
    () => props.selected,
    (selected) => {
        if (submitting) {
            return; // a save's own reload: onSuccess decides what to take from it
        }
        if (selected && selected.id === expecting) {
            expecting = null;
            currentId.value = selected.id;
            fill(selected);
            return;
        }
        if (expecting !== null) {
            return; // an older reload while another sheet is on its way
        }
        if (selected === null && currentId.value !== null) {
            load(null); // the sheet is gone (deleted)
        } else if (selected && selected.id === currentId.value && !isDirty()) {
            fill(selected); // a refresh of the sheet on screen
        } else if (selected && selected.id !== currentId.value) {
            currentId.value = selected.id; // arrived by URL (history navigation)
            fill(selected);
        }
    },
);

/** The edits on screen become the clean state (the user has already chosen Save or Discard for them). */
function markClean(): void {
    form.defaults();
    form.reset();
    linesBaseline.value = lineSignature(lines.value);
}

watch(
    () => props.sheets,
    () => {
        if (queryPending) {
            // Execute Query passed the unsaved guard on its way out: the first result replaces what is on screen
            queryPending = false;
            markClean();
            load(props.sheets[0]?.id ?? null);
        }
    },
);

let pendingSelect: number | null = null;
watch(flashSelect.pending, (id) => {
    // a just-saved sheet is selected once its id is flashed (the list may not even hold it: it is fetched by id);
    // the flash arrives before the save's onSuccess, which is where the form is marked clean, so it waits for it
    if (id !== null) {
        flashSelect.take();
        if (submitting) {
            pendingSelect = id;
        } else {
            load(id);
        }
    }
});

watch(
    [index, () => props.sheets.length],
    ([i, n]) => statusLine.setRecord(i + 1, n),
    { immediate: true },
);
watch(
    () => isDirty(),
    (d) => statusLine.setDirty(d),
    { immediate: true },
);
watch(
    () => form.department_id,
    (id, old) => {
        // operations and machines are limited to the department: a new department blanks them
        if (filling || id === old) {
            return;
        }
        form.machine_id = null;
        machineDisplay.value = '';
        contexts.value = {};
        for (const r of lines.value) {
            Object.assign(r, {
                operation_id: null,
                operation: '',
                is_stretch: false,
                lot_route_step_id: null,
                available: null,
            });
            clearOverrides(r);
        }
    },
    { flush: 'sync' },
);

// ---- lot context --------------------------------------------------------------------------------------------

function contextFor(lotId: number): Promise<LotContext> {
    const cached = contexts.value[lotId];
    if (cached) {
        return Promise.resolve(cached);
    }
    const pending = inFlight.get(lotId);
    if (pending) {
        return pending;
    }
    const departmentId = form.department_id;
    const p = getJson<LotContext>(
        `/production/sheets/lot-context/${lotId}?department_id=${departmentId ?? ''}`,
    )
        .then((ctx) => {
            if (form.department_id === departmentId) {
                contexts.value = { ...contexts.value, [lotId]: ctx };
            }
            return ctx;
        })
        .finally(() => inFlight.delete(lotId));
    inFlight.set(lotId, p);
    return p;
}

function contextOf(row: SheetLineRow): LotContext | undefined {
    return row.lot_id === null ? undefined : contexts.value[row.lot_id];
}

async function prefetchContexts(): Promise<void> {
    if (form.department_id === null) {
        return;
    }
    const ids = [
        ...new Set(
            lines.value
                .map((r) => r.lot_id)
                .filter((id): id is number => id !== null),
        ),
    ];
    if (ids.length === 0) {
        return;
    }
    try {
        await Promise.all(ids.map((id) => contextFor(id)));
    } catch {
        return; // the hint column stays blank; picking a lot again reports the failure
    }
    for (const r of lines.value) {
        r.available =
            r.lot_id === null
                ? null
                : availableFor(r, contexts.value[r.lot_id], form.department_id);
    }
}

function hintFor(row: SheetLineRow): void {
    const ctx = contextOf(row);
    if (!ctx) {
        statusLine.setHint(
            row.lot_id === null ? t('production.sheet.lot_hint') : '',
        );
        return;
    }
    if (ctx.state !== 'open') {
        statusLine.setHint(
            t('production.sheet.lot_state_hint', {
                lot: ctx.lot_no,
                state: t(`production.states.${ctx.state}`),
            }),
        );
        return;
    }
    const qty = availableFor(row, ctx, form.department_id);
    statusLine.setHint(
        t('production.sheet.available_hint', {
            qty: formatQty(qty ?? 0),
            position: sourcePosition(row, ctx, form.department_id) ?? '—',
        }),
    );
}

/** A lot picked (list or typed): fill the lot fields, then its default step, operation, widths and availability. */
async function applyLot(
    uid: string | undefined,
    picked: LovRow,
): Promise<void> {
    if (!applyLotPick(lines.value, uid, picked)) {
        return;
    }
    let ctx: LotContext;
    try {
        ctx = await contextFor(picked.id);
    } catch {
        statusLine.setMessage(t('forms.lov_failed'), 'error');
        return;
    }
    const row = rowByUid(lines.value, uid);
    if (!row || row.lot_id !== picked.id) {
        return; // the row went away or was re-picked meanwhile
    }
    applyLotContext(
        lines.value,
        uid,
        ctx,
        props.operations,
        form.department_id,
    );
    if (ctx.state !== 'open') {
        statusLine.setMessage(
            t('production.errors.lot_not_open', {
                lot: ctx.lot_no,
                state: t(`production.states.${ctx.state}`),
            }),
            'error',
        );
    } else if (!ctx.colour_approved) {
        statusLine.setMessage(
            t('production.sheet.colour_hint', {
                lot: ctx.lot_no,
                status:
                    ctx.colour_status ?? t('production.sheet.no_colour_status'),
            }),
            'info',
        );
    } else {
        statusLine.setMessage('', 'info');
    }
    hintFor(row);
}

/**
 * A lot number typed into the cell (spec §6.3.2): one match fills the row, several open the list to choose. Held
 * lots are matched too, so a held lot is reported as such rather than as unknown.
 */
async function resolveTypedLot(
    uid: string | undefined,
    typed: string,
): Promise<void> {
    let rows: LovRow[];
    try {
        rows = (
            await fetchLov('lots', {
                exact: typed,
                filters: { states: 'open,hold' },
            })
        ).data;
    } catch {
        statusLine.setMessage(t('forms.lov_failed'), 'error');
        return;
    }
    const row = rowByUid(lines.value, uid);
    if (!row || row.lot !== typed) {
        return;
    }
    if (rows.length === 1) {
        await applyLot(uid, rows[0]);
    } else if (rows.length === 0) {
        statusLine.setMessage(
            t('production.sheet.lot_not_found', { value: typed }),
            'error',
        );
    } else {
        lov.openFor('lots', {}, (picked) => void applyLot(uid, picked), typed);
    }
}

function onLotPicked(picked: LovRow, cell: GridLovCell<SheetLineRow>): void {
    void applyLot(cell.data?._uid, picked);
}

function onOperationPicked(
    picked: LovRow,
    cell: GridLovCell<SheetLineRow>,
): void {
    const row = rowByUid(lines.value, cell.data?._uid);
    if (!row) {
        return;
    }
    applyOperationPick(
        lines.value,
        row._uid,
        operationFromPick(picked),
        contextOf(row),
        form.department_id,
        props.operations,
    );
    hintFor(row);
}

// ---- grid ---------------------------------------------------------------------------------------------------

const editableCell = (): boolean => canEditSheet.value;
const num = (p: ValueFormatterParams<SheetLineRow>): string =>
    p.value === null || p.value === undefined || p.value === ''
        ? ''
        : formatQty(p.value);

const columns = computed<ColumnDef<SheetLineRow>[]>(() => [
    {
        field: 'batch_no',
        headerName: t('production.fields.batch_no'),
        width: 90,
        editable: editableCell,
    },
    {
        field: 'lot',
        headerName: t('production.fields.lot_no'),
        flex: 2,
        minWidth: 200,
        editable: editableCell,
        cellRenderer: lov.renderer(
            'lots',
            () => ({}),
            onLotPicked,
            editableCell,
            { inline: true },
        ),
        valueSetter: (p: ValueSetterParams<SheetLineRow>) => {
            // the typed text lands on the grid's copy; onCellValueChanged resolves it on the model row
            const typed = String(p.newValue ?? '').trim();
            if (!p.data || typed === (p.oldValue ?? '')) {
                return false;
            }
            Object.assign(p.data, {
                lot: typed,
                lot_id: null,
                lot_route_step_id: null,
                available: null,
            });
            return true;
        },
    },
    {
        field: 'lot_route_step_id',
        headerName: t('production.fields.step'),
        flex: 1,
        minWidth: 150,
        editable: (p: EditableCallbackParams<SheetLineRow>) =>
            editableCell() && p.data?.lot_id !== null,
        cellEditor: 'agSelectCellEditor',
        cellEditorParams: (p: ICellEditorParams<SheetLineRow>) => ({
            // "automatic" is an intent the server only takes for a new line (on a saved line null means "as saved"),
            // so a saved line keeps a step once it has one
            values: [
                ...(p.data?.id !== null && p.data?.lot_route_step_id !== null
                    ? []
                    : [null]),
                ...departmentSteps(
                    p.data?.lot_id == null
                        ? undefined
                        : contexts.value[p.data.lot_id],
                    form.department_id,
                ).map((s) => s.id),
            ],
        }),
        valueFormatter: (p) => {
            if (p.value === null || p.value === undefined) {
                return p.data?.lot_id ? t('production.sheet.step_auto') : '';
            }
            const ctx = p.data?.lot_id
                ? contexts.value[p.data.lot_id]
                : undefined;
            const step = ctx?.steps.find((s) => s.id === p.value);
            return step ? stepLabel(step) : `#${String(p.value)}`;
        },
    },
    {
        field: 'operation',
        headerName: t('production.fields.operation'),
        flex: 2,
        minWidth: 150,
        editable: false,
        cellRenderer: lov.renderer(
            'operations',
            () => ({ department_id: form.department_id }),
            onOperationPicked,
            () => editableCell() && form.department_id !== null,
        ),
    },
    {
        field: 'is_reprocess',
        headerName: t('production.fields.reprocess'),
        width: 90,
        // a reprocess-kind operation is always a reprocess line (the service says so too)
        editable: (p: EditableCallbackParams<SheetLineRow>) =>
            editableCell() &&
            operationById(p.data?.operation_id ?? null)?.kind !== 'reprocess',
        cellRenderer: 'agCheckboxCellRenderer',
        cellEditor: 'agCheckboxCellEditor',
    },
    {
        field: 'reprocess_reason',
        headerName: t('production.fields.reprocess_reason'),
        flex: 1,
        minWidth: 120,
        editable: (p: EditableCallbackParams<SheetLineRow>) =>
            editableCell() && Boolean(p.data?.is_reprocess),
        cellEditor: 'agSelectCellEditor',
        cellEditorParams: {
            values: ['', ...props.reprocessReasons.map((r) => String(r.value))],
        },
        valueFormatter: (p) =>
            props.reprocessReasons.find((r) => r.value === p.value)?.label ??
            (p.value ? String(p.value) : ''),
    },
    {
        field: 'qty_in',
        headerName: t('production.fields.qty_in'),
        width: 110,
        editable: editableCell,
        type: 'numericColumn',
        cellEditor: 'agNumberCellEditor',
        cellEditorParams: { precision: 2, min: 0 },
        valueFormatter: num,
    },
    {
        field: 'qty_out',
        headerName: t('production.fields.qty_out'),
        width: 110,
        editable: editableCell,
        type: 'numericColumn',
        cellEditor: 'agNumberCellEditor',
        cellEditorParams: { precision: 2, min: 0 },
        valueFormatter: num,
    },
    {
        field: 'available',
        headerName: t('production.sheet.available'),
        width: 110,
        editable: false,
        type: 'numericColumn',
        cellClass: 'text-muted-foreground',
        valueFormatter: num,
    },
    {
        field: 'output_type',
        headerName: t('production.fields.output_type'),
        width: 90,
        editable: editableCell,
        cellEditor: 'agSelectCellEditor',
        cellEditorParams: { values: ['', 'dyed', 'print'] },
        valueFormatter: (p) =>
            p.value
                ? t(`production.sheet.output_types.${String(p.value)}`)
                : '',
    },
    {
        field: 'g_width',
        headerName: t('production.fields.g_width'),
        width: 80,
        editable: editableCell,
        type: 'numericColumn',
        cellEditor: 'agNumberCellEditor',
        cellEditorParams: { precision: 2, min: 0 },
        valueFormatter: num,
    },
    {
        field: 'f_width',
        headerName: t('production.fields.f_width'),
        width: 80,
        editable: editableCell,
        type: 'numericColumn',
        cellEditor: 'agNumberCellEditor',
        cellEditorParams: { precision: 2, min: 0 },
        valueFormatter: num,
    },
    {
        field: 'remarks',
        headerName: t('production.fields.remarks'),
        flex: 1,
        minWidth: 120,
        editable: editableCell,
    },
    {
        field: 'colour_override',
        headerName: t('production.sheet.colour_override'),
        flex: 1,
        minWidth: 120,
        editable: false,
        cellClass: 'text-muted-foreground',
    },
]);

const rowClassRules = {
    'bg-destructive/10': (p: RowClassParams<SheetLineRow>) =>
        p.rowIndex !== undefined && rowErrors.value.has(p.rowIndex),
};

/** Fields that reach the ledger: a change voids the override answers and moves the availability figure. */
const POSTED_FIELDS = new Set([
    'lot',
    'lot_route_step_id',
    'is_reprocess',
    'qty_in',
    'qty_out',
]);

function onCellValueChanged(e: CellValueChangedEvent<SheetLineRow>): void {
    // DetailGrid has already handed the grid's rows back: the model row is the one with this uid
    const col = e.column.getColId();
    const row = rowByUid(lines.value, e.data._uid);
    if (!row || !POSTED_FIELDS.has(col)) {
        return;
    }
    clearOverrides(row);
    if (col === 'lot') {
        if (row.lot !== '') {
            void resolveTypedLot(row._uid, row.lot);
        }
        return;
    }
    if (
        col === 'is_reprocess' &&
        operationById(row.operation_id)?.kind === 'reprocess'
    ) {
        row.is_reprocess = true;
    }
    if (col === 'is_reprocess' && !row.is_reprocess) {
        row.reprocess_reason = null;
    }
    row.available = availableFor(row, contextOf(row), form.department_id);
    hintFor(row);
}

function onCellFocused(e: CellFocusedEvent<SheetLineRow>): void {
    if (e.rowIndex === null || e.rowIndex === undefined) {
        return;
    }
    const row = rowByUid(
        lines.value,
        e.api.getDisplayedRowAtIndex(e.rowIndex)?.data?._uid,
    );
    if (row) {
        hintFor(row);
    }
}

function onCellKeyDown(e: CellKeyDownEvent<SheetLineRow>): void {
    lov.onCellKeyDown(e);
}

// ---- save / delete ------------------------------------------------------------------------------------------

function save(): Promise<boolean> {
    return new Promise((resolve) => {
        grid.value?.stopEditing(); // the cell being typed in is part of the save
        if (!canEditRecord.value || !isDirty()) {
            statusLine.setMessage(t('forms.nothing_to_save'), 'info');
            resolve(false);
            return;
        }
        const incomplete = incompleteRowIndexes(lines.value);
        if (incomplete.length > 0) {
            showRowErrors(incomplete);
            statusLine.setMessage(
                t('production.sheet.incomplete_line', { n: incomplete[0] + 1 }),
                'error',
            );
            resolve(false);
            return;
        }
        submit(resolve);
    });
}

function submit(resolve: (ok: boolean) => void): void {
    form.transform((d) => ({
        ...d,
        downtime_minutes: d.downtime_minutes === '' ? 0 : d.downtime_minutes,
        lines: linePayload(lines.value),
    }));
    submitting = true;
    const opts = {
        preserveScroll: true,
        onSuccess: () => {
            submitting = false;
            contexts.value = {};
            if (
                currentId.value !== null &&
                props.selected?.id === currentId.value
            ) {
                fill(props.selected); // the saved lines with their ids
            } else {
                showRowErrors([]);
                markClean();
            }
            statusLine.setMessage(t('forms.saved'), 'success');
            if (pendingSelect !== null) {
                const id = pendingSelect;
                pendingSelect = null;
                load(id); // the sheet just created
            }
            resolve(true);
        },
        onError: (errors: Record<string, string>) => {
            submitting = false;
            void handleErrors(errors, resolve);
        },
        onFinish: () => {
            submitting = false;
        },
    };
    if (currentId.value === null) {
        form.post('/production/sheets', opts);
    } else {
        form.put(`/production/sheets/${currentId.value}`, opts);
    }
}

/**
 * A refused save: highlight the rows, say which line, and — when the server keyed an override hint for this user
 * (`lines.N.override_reason`), or the colour gate asks a user who may approve — ask for the reason and submit again
 * with it on the line.
 */
async function handleErrors(
    errors: Record<string, string>,
    resolve: (ok: boolean) => void,
): Promise<void> {
    const errs = lineErrors(errors, lines.value);
    showRowErrors(errs.map((e) => e.row));
    const first = errs[0];
    statusLine.setMessage(
        first ? lineErrorText(first) : (Object.values(errors)[0] ?? ''),
        'error',
    );
    const ask = overrideToAsk(errs, lines.value, canOverrideColour);
    if (!ask) {
        resolve(false);
        return;
    }
    const uid = lines.value[ask.error.row]?._uid;
    const reason = await prompt.ask({
        title: t(
            ask.kind === 'qty'
                ? 'production.sheet.override_title'
                : 'production.sheet.colour_override_title',
        ),
        message: ask.message,
        label: t(
            ask.kind === 'qty'
                ? 'production.fields.override_reason'
                : 'production.fields.colour_override_reason',
        ),
    });
    // the rows may have moved while the prompt was open: the line is found by its uid again, not by its index
    const row = rowByUid(lines.value, uid);
    if (reason === null || !row) {
        resolve(false);
        return;
    }
    row[ask.kind === 'qty' ? 'override_reason' : 'colour_override_reason'] =
        reason;
    submit(resolve);
}

async function deleteRecord(): Promise<void> {
    const id = currentId.value;
    if (id === null || !canEditSheet.value) {
        return;
    }
    const ok = await confirm.ask(t('forms.confirm_delete'), {
        destructive: true,
        confirmLabel: t('forms.actions.deleteRecord'),
    });
    if (!ok) {
        return;
    }
    router.delete(`/production/sheets/${id}`, {
        preserveScroll: true,
        onSuccess: () => {
            statusLine.setMessage(t('forms.deleted'), 'success');
            load(null);
        },
        onError: (errors) => {
            form.setError(errors as Partial<Record<keyof HeaderForm, string>>);
            const errs = lineErrors(errors, lines.value);
            showRowErrors(errs.map((e) => e.row));
            statusLine.setMessage(
                errs[0]
                    ? lineErrorText(errs[0])
                    : (Object.values(errors)[0] ?? ''),
                'error',
            );
        },
    });
}

/** "Split batch total" (spec §6.3.4): a total spread over the lines of one batch in proportion to their available metres. */
async function splitBatch(): Promise<void> {
    if (!canEditSheet.value) {
        return;
    }
    grid.value?.stopEditing();
    const batches = [
        ...new Set(
            lines.value
                .map((r) => (r.batch_no ?? '').trim())
                .filter((b) => b !== ''),
        ),
    ];
    const batch = await prompt.ask({
        title: t('production.sheet.split_title'),
        label: t('production.sheet.split_batch_label'),
        initial: batches[0] ?? '',
    });
    if (batch === null) {
        return;
    }
    // the rows are re-found by uid after every await: a grid edit meanwhile replaces the model objects
    const uids = lines.value
        .filter((r) => (r.batch_no ?? '').trim() === batch && r.lot_id !== null)
        .map((r) => r._uid);
    if (uids.length === 0) {
        statusLine.setMessage(
            t('production.sheet.split_no_lines', { batch }),
            'error',
        );
        return;
    }
    const totalText = await prompt.ask({
        title: t('production.sheet.split_title'),
        message: t('production.sheet.split_batch_label') + `: ${batch}`,
        label: t('production.sheet.split_total_label'),
        type: 'number',
    });
    const total = Number(totalText);
    if (totalText === null || !(total > 0)) {
        return;
    }
    try {
        const picked = () =>
            uids.flatMap((uid) => {
                const r = rowByUid(lines.value, uid);
                return r && r.lot_id !== null ? [r] : [];
            });
        await Promise.all(picked().map((r) => contextFor(r.lot_id as number)));
        const rows = picked();
        const available = Object.fromEntries(
            rows.map((r, i) => [
                String(i),
                availableFor(
                    r,
                    contexts.value[r.lot_id as number],
                    form.department_id,
                ) ?? 0,
            ]),
        );
        const res = await postJson<{ allocations: Record<string, number> }>(
            '/production/sheets/split-batch',
            { total, available },
        );
        let n = 0;
        rows.forEach((sent, i) => {
            const r = rowByUid(lines.value, sent._uid);
            const qty = res.allocations[String(i)];
            if (r && qty !== undefined) {
                r.qty_in = qty;
                r.qty_out ??= qty;
                clearOverrides(r);
                n += 1;
            }
        });
        statusLine.setMessage(
            t('production.sheet.split_done', {
                batch,
                total: formatQty(total),
                n,
            }),
            'success',
        );
    } catch (e) {
        statusLine.setMessage(
            e instanceof ApiError
                ? (Object.values(e.errors)[0]?.[0] ?? e.message)
                : t('forms.lov_failed'),
            'error',
        );
    }
}

const guard = useUnsavedGuard(isDirty, save);

/**
 * Execute Query (F8) behind the unsaved guard: with edits on screen the user chooses Save (the record is saved
 * first, even while query mode hides it), Discard (the first result replaces the edits) or Cancel (query mode and
 * the criteria stay as typed). Outside query mode it re-runs the last criteria.
 */
function executeQuery(): void {
    guard.guard(() => {
        const q = inQuery.value ? query.takeCriteria() : {};
        queryPending = true;
        router.get(
            '/production/sheets',
            { q },
            {
                only: ['sheets', 'criteria'],
                preserveState: true,
                preserveScroll: true,
            },
        );
    });
}

function onAction(action: FormsAction): void {
    switch (action) {
        case 'save':
            void save();
            break;
        case 'newRecord':
            guard.guard(() => load(null));
            break;
        case 'deleteRecord':
            void deleteRecord();
            break;
        case 'enterQuery':
            query.enterQuery();
            break;
        case 'executeQuery':
            executeQuery();
            break;
        case 'previousRecord':
            if (index.value > 0) {
                guard.guard(() => load(props.sheets[index.value - 1].id));
            }
            break;
        case 'nextRecord':
            if (index.value < props.sheets.length - 1) {
                guard.guard(() => load(props.sheets[index.value + 1].id));
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

const sheetColumns = computed<ColumnDef<SheetListRow>[]>(() => [
    {
        field: 'sheet_date',
        headerName: t('production.fields.sheet_date'),
        flex: 1,
    },
    { field: 'shift', headerName: t('production.fields.shift'), width: 80 },
    {
        field: 'department',
        headerName: t('production.fields.department'),
        flex: 2,
    },
    { field: 'machine', headerName: t('production.fields.machine'), flex: 2 },
    {
        field: 'lines_count',
        headerName: t('production.sheet.lines_count'),
        width: 90,
        type: 'numericColumn',
    },
    {
        field: 'qty_out',
        headerName: t('production.fields.qty_out'),
        flex: 1,
        type: 'numericColumn',
        valueFormatter: (p) => formatQty(p.value),
    },
    {
        field: 'entered_by',
        headerName: t('production.sheet.entered_by'),
        flex: 1,
    },
]);
</script>

<template>
    <Head :title="t('production.sheet.title')" />
    <div class="flex min-w-0 flex-col gap-3 p-3 sm:p-4">
        <h1 class="text-lg font-semibold">{{ t('production.sheet.title') }}</h1>
        <FormsToolbar
            :mode="query.mode.value"
            :disabled="{
                save: !canEditSheet,
                newRecord: inQuery,
                deleteRecord: currentId === null || !canEditSheet,
                previousRecord: inQuery || index <= 0,
                nextRecord: inQuery || index >= sheets.length - 1,
            }"
            @action="onAction"
        />
        <FormBlock :title="t('production.sheet.header')">
            <FormField
                :label="`${t('production.fields.sheet_date')} *`"
                for="f-date"
                :span="2"
                :error="form.errors.sheet_date"
            >
                <Input
                    v-if="inQuery"
                    id="f-date"
                    v-model="criteria.sheet_date as string"
                    class="border-warning/70"
                    :placeholder="t('app.date_format_hint')"
                />
                <Input
                    v-else
                    id="f-date"
                    v-model="form.sheet_date"
                    :placeholder="t('app.date_format_hint')"
                    :disabled="!canEditSheet"
                />
            </FormField>
            <FormField
                :label="`${t('production.fields.shift')} *`"
                for="f-shift"
                :span="2"
                :error="form.errors.shift_id"
            >
                <Input
                    v-if="inQuery"
                    id="f-shift"
                    v-model="criteria.shift as string"
                    class="border-warning/70"
                />
                <select
                    v-else
                    id="f-shift"
                    v-model="form.shift_id"
                    :disabled="!canEditSheet"
                    class="bg-background h-9 w-full rounded-md border px-2 text-sm"
                >
                    <option :value="null">{{ t('forms.select') }}</option>
                    <option v-for="o in shifts" :key="o.value" :value="o.value">
                        {{ o.label }}
                    </option>
                </select>
            </FormField>
            <FormField
                :label="`${t('production.fields.department')} *`"
                for="f-department"
                :span="3"
                :error="form.errors.department_id"
            >
                <Input
                    v-if="inQuery"
                    id="f-department"
                    v-model="criteria.department as string"
                    class="border-warning/70"
                />
                <select
                    v-else
                    id="f-department"
                    v-model="form.department_id"
                    :disabled="!canEditSheet"
                    class="bg-background h-9 w-full rounded-md border px-2 text-sm"
                >
                    <option :value="null">{{ t('forms.select') }}</option>
                    <option
                        v-for="o in departments"
                        :key="o.value"
                        :value="o.value"
                        :disabled="!allowedDepartment(o.value as number)"
                    >
                        {{ o.label }}
                    </option>
                </select>
            </FormField>
            <FormField
                :label="`${t('production.fields.machine')} *`"
                for="f-machine"
                :span="3"
                :error="form.errors.machine_id"
                :hint="t('production.sheet.machine_hint')"
            >
                <Input
                    v-if="inQuery"
                    id="f-machine"
                    v-model="criteria.machine as string"
                    class="border-warning/70"
                />
                <LovField
                    v-else
                    id="f-machine"
                    v-model="form.machine_id"
                    v-model:display="machineDisplay"
                    name="machines"
                    :filters="{ department_id: form.department_id }"
                    :disabled="!canEditSheet || form.department_id === null"
                />
            </FormField>
            <FormField
                :label="t('production.fields.downtime')"
                for="f-downtime"
                :span="2"
                :error="form.errors.downtime_minutes"
            >
                <Input v-if="inQuery" id="f-downtime" disabled />
                <Input
                    v-else
                    id="f-downtime"
                    v-model="form.downtime_minutes"
                    type="number"
                    min="0"
                    max="1440"
                    :disabled="!canEditSheet"
                    class="text-end tabular-nums"
                />
            </FormField>
            <FormField
                :label="t('production.fields.remarks')"
                for="f-remarks"
                :span="12"
                :error="form.errors.remarks"
            >
                <Input v-if="inQuery" id="f-remarks" disabled />
                <Input
                    v-else
                    id="f-remarks"
                    v-model="form.remarks"
                    :disabled="!canEditSheet"
                />
            </FormField>
        </FormBlock>
        <p v-if="linesError" class="text-destructive text-sm">
            {{ linesError }}
        </p>

        <div v-if="!inQuery" class="flex flex-col gap-1">
            <div class="flex items-center gap-2">
                <h2
                    class="text-muted-foreground text-xs font-semibold tracking-wide uppercase"
                >
                    {{ t('production.sheet.lines') }}
                </h2>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="ms-auto"
                    :disabled="!canEditSheet"
                    @click="splitBatch"
                >
                    {{ t('production.sheet.split') }}
                </Button>
            </div>
            <DetailGrid
                ref="grid"
                v-model:rows="lines"
                :columns="columns"
                :new-row="blankLineRow"
                :row-class-rules="rowClassRules"
                @cell-key-down="onCellKeyDown"
                @cell-value-changed="onCellValueChanged"
                @cell-focused="onCellFocused"
            />
            <p class="text-muted-foreground text-xs">
                {{ t('production.sheet.lot_hint') }}
            </p>
        </div>

        <FormBlock v-if="!inQuery" :title="t('production.sheet.totals_title')">
            <table class="col-span-12 w-full max-w-xl text-sm tabular-nums">
                <thead class="text-muted-foreground text-xs">
                    <tr>
                        <th class="py-1 text-start font-medium"></th>
                        <th
                            v-for="g in TOTAL_GROUPS"
                            :key="g"
                            class="px-2 py-1 text-end font-medium"
                        >
                            {{
                                g === 'all'
                                    ? t('production.sheet.all')
                                    : t(`production.sheet.output_types.${g}`)
                            }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="b in TOTAL_BUCKETS"
                        :key="b"
                        :class="b === 'total' ? 'border-t font-semibold' : ''"
                    >
                        <td class="py-1 text-start">
                            {{ t(`production.sheet.totals.${b}`) }}
                        </td>
                        <td
                            v-for="g in TOTAL_GROUPS"
                            :key="g"
                            class="px-2 py-1 text-end"
                            :data-total="`${g}.${b}`"
                        >
                            {{ formatQty(totals[g][b]) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </FormBlock>

        <h2
            class="text-muted-foreground text-xs font-semibold tracking-wide uppercase"
        >
            {{ t('production.sheet.list') }}
        </h2>
        <DataGrid
            :rows="sheets"
            :columns="sheetColumns"
            :selected-id="currentId"
            @row-selected="(r) => guard.guard(() => load(r.id))"
        />

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
        <LovDialog
            v-model:open="lov.open.value"
            :name="lov.name.value"
            :filters="lov.filters.value"
            :initial-query="lov.initialQuery.value"
            @select="lov.select"
        />
    </div>
</template>
