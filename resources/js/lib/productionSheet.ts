import { t } from '@/lib/i18n';
import type { LovRow } from '@/lib/lov';
import type { TotalsInput } from '@/lib/sheetTotals';
import { newUid } from '@/lib/uid';
import type {
    LotContext,
    LotContextStep,
    OperationOption,
    SheetDetail,
    SheetLinePayload,
    SheetLineRow,
} from '@/types/production';

/** A blank line the grid appends on Down-arrow. */
export function blankLineRow(): SheetLineRow {
    return {
        _uid: newUid(),
        id: null,
        batch_no: null,
        lot_id: null,
        lot: '',
        lot_route_step_id: null,
        operation_id: null,
        operation: '',
        is_stretch: false,
        is_reprocess: false,
        reprocess_reason: null,
        qty_in: null,
        qty_out: null,
        output_type: null,
        g_width: null,
        f_width: null,
        remarks: null,
        colour_override: null,
        available: null,
        override_reason: null,
        colour_override_reason: null,
    };
}

/** The grid rows of a loaded sheet. */
export function lineRowsFrom(lines: SheetDetail['lines']): SheetLineRow[] {
    return lines.map((l) => ({
        ...l,
        _uid: newUid(),
        available: null,
        override_reason: null,
        colour_override_reason: null,
    }));
}

/** The model row the grid cell with this client id belongs to (the grid holds copies, never the model objects). */
export function rowByUid(
    rows: SheetLineRow[],
    uid: string | undefined,
): SheetLineRow | undefined {
    return uid === undefined ? undefined : rows.find((r) => r._uid === uid);
}

/** A row with a lot and an operation: what a save sends. */
export function isComplete(row: SheetLineRow): boolean {
    return row.lot_id !== null && row.operation_id !== null;
}

/** A row that has started (a lot picked or typed, or an operation picked) but is not yet a complete line. */
export function isIncomplete(row: SheetLineRow): boolean {
    const started =
        row.lot_id !== null ||
        row.lot.trim() !== '' ||
        row.operation_id !== null;
    return started && !isComplete(row);
}

/** Grid row indexes that hold an incomplete line (a save must not silently drop them). */
export function incompleteRowIndexes(rows: SheetLineRow[]): number[] {
    return rows.flatMap((r, i) => (isIncomplete(r) ? [i] : []));
}

const toNumber = (v: unknown): number | null =>
    v === null || v === undefined || v === '' || !Number.isFinite(Number(v))
        ? null
        : Number(v);
const toText = (v: string | number | null | undefined): string | null => {
    const text = v === null || v === undefined ? '' : String(v).trim();
    return text === '' ? null : text;
};

/** What a save sends: rows with a lot and an operation; blank and incomplete rows are dropped, `_uid` never travels. */
export function linePayload(rows: SheetLineRow[]): SheetLinePayload[] {
    return rows.filter(isComplete).map((r) => ({
        id: r.id,
        batch_no: toText(r.batch_no),
        lot_id: r.lot_id as number,
        lot_route_step_id: toNumber(r.lot_route_step_id), // a select editor may hand back '' for null
        operation_id: r.operation_id as number,
        is_reprocess: Boolean(r.is_reprocess),
        reprocess_reason: r.is_reprocess ? toText(r.reprocess_reason) : null,
        qty_in: toNumber(r.qty_in),
        qty_out: toNumber(r.qty_out),
        output_type: r.output_type || null,
        g_width: toNumber(r.g_width),
        f_width: toNumber(r.f_width),
        remarks: toText(r.remarks),
        override_reason: toText(r.override_reason),
        colour_override_reason: toText(r.colour_override_reason),
    }));
}

/**
 * The part of the rows a save changes, plus every started-but-incomplete row (it blocks a save, so it is an
 * unsaved edit too). Override reasons are excluded: they are answers, not edits. Blank rows count for nothing.
 */
export function lineSignature(rows: SheetLineRow[]): string {
    const parts: unknown[] = [];
    for (const r of rows) {
        if (isComplete(r)) {
            parts.push({
                ...linePayload([r])[0],
                override_reason: null,
                colour_override_reason: null,
            });
        } else if (isIncomplete(r)) {
            parts.push({
                incomplete: true,
                lot: r.lot.trim(),
                lot_id: r.lot_id,
                operation_id: r.operation_id,
            });
        }
    }
    return JSON.stringify(parts);
}

/** Grid row index of each payload row (linePayload() drops incomplete rows). */
export function payloadToGridRows(rows: SheetLineRow[]): number[] {
    return rows.flatMap((r, i) => (isComplete(r) ? [i] : []));
}

export type LineError = { row: number; field: string; message: string };

/** Server errors keyed `lines.<payload row>.<field>`, mapped to grid rows, in payload order. */
export function lineErrors(
    errors: Record<string, string | string[] | undefined>,
    rows: SheetLineRow[],
): LineError[] {
    const gridRows = payloadToGridRows(rows);
    const out: LineError[] = [];
    for (const [key, value] of Object.entries(errors)) {
        const m = /^lines\.(\d+)\.([a-z_]+)$/.exec(key);
        if (!m) {
            continue;
        }
        const row = gridRows[Number(m[1])];
        if (row !== undefined) {
            out.push({
                row,
                field: m[2],
                message: Array.isArray(value)
                    ? (value[0] ?? '')
                    : (value ?? ''),
            });
        }
    }
    return out.sort((a, b) => a.row - b.row);
}

/** The status-line text of a line error: "Line n: message" with the 1-based grid row. */
export function lineErrorText(e: LineError): string {
    return t('production.sheet.line_error', {
        n: e.row + 1,
        message: e.message,
    });
}

/**
 * The override the server invites for a refused save: it keys `lines.N.override_reason` (a hint it adds only for
 * users with `production.override`) next to the insufficient-metres error, and `lines.N.colour_override_reason`
 * for the colour gate (asked only of users who may approve). A row that already answered is not asked again.
 */
export function overrideToAsk(
    errs: LineError[],
    rows: SheetLineRow[],
    canOverrideColour: boolean,
): { kind: 'qty' | 'colour'; error: LineError; message: string } | null {
    const qty = errs.find(
        (e) => e.field === 'override_reason' && !rows[e.row]?.override_reason,
    );
    if (qty) {
        // the figure the user is overriding is on the qty_in error of the same row
        const figure = errs.find(
            (e) => e.row === qty.row && e.field === 'qty_in',
        );
        return {
            kind: 'qty',
            error: qty,
            message: lineErrorText(figure ?? qty),
        };
    }
    const colour = errs.find(
        (e) =>
            e.field === 'colour_override_reason' &&
            canOverrideColour &&
            !rows[e.row]?.colour_override_reason,
    );
    return colour
        ? { kind: 'colour', error: colour, message: lineErrorText(colour) }
        : null;
}

/** The lot's route steps a line of this department may be recorded against (active steps only). */
export function departmentSteps(
    ctx: LotContext | undefined,
    departmentId: number | null,
): LotContextStep[] {
    return (ctx?.steps ?? []).filter(
        (s) =>
            s.status !== 'skipped' &&
            (departmentId === null || s.department_id === departmentId),
    );
}

/** The select label of a route step: "seq. OPERATION (status)". */
export function stepLabel(step: LotContextStep): string {
    return t('production.sheet.step_label', {
        seq: step.seq,
        operation: step.operation,
        status: t(`production.step_status.${step.status}`),
    });
}

type StepInput = Pick<
    SheetLineRow,
    'lot_route_step_id' | 'is_reprocess' | 'operation_id'
>;

const isPending = (s: LotContextStep): boolean =>
    s.status === 'pending' || s.status === 'partial';

/**
 * The route step the service will record the line against (ProductionSheetService::resolve()):
 * - a step picked on the row: that step;
 * - a planned line without one: the pending/partial step of the line's operation in the department, else the
 *   department's next pending step, else the last step of the line's operation (a repeat of finished work);
 * - a reprocess line without one: the department's step holding the most metres at its target;
 * - null: nothing on the route for it — the line acts in place at the largest floor position.
 */
export function resolvedStep(
    row: StepInput,
    ctx: LotContext,
    departmentId: number | null,
): LotContextStep | null {
    if (row.lot_route_step_id !== null) {
        return ctx.steps.find((s) => s.id === row.lot_route_step_id) ?? null;
    }
    const inDepartment = departmentSteps(ctx, departmentId);
    if (row.is_reprocess) {
        const at = (s: LotContextStep): number =>
            s.to_position === null ? 0 : (ctx.balances[s.to_position] ?? 0);
        return (
            inDepartment
                .filter((s) => at(s) > 0)
                .sort((a, b) => at(b) - at(a))[0] ?? null
        );
    }
    const ofOperation = inDepartment.filter(
        (s) => s.operation_id === row.operation_id,
    );
    return (
        ofOperation.find(isPending) ??
        inDepartment.find(isPending) ??
        ofOperation[ofOperation.length - 1] ??
        null
    );
}

/**
 * Metres the line can draw on, the way the service resolves it: a planned line takes from its step's source
 * position, a reprocess line acts in place at its step's target, a line with no step at the largest floor position.
 */
export function availableFor(
    row: StepInput,
    ctx: LotContext | undefined,
    departmentId: number | null,
): number | null {
    if (!ctx) {
        return null;
    }
    const step = resolvedStep(row, ctx, departmentId);
    if (!step) {
        return ctx.floor_available;
    }
    if (row.is_reprocess) {
        return step.to_position === null
            ? 0
            : (ctx.balances[step.to_position] ?? 0);
    }
    return step.available;
}

/** The position a line draws on (for the hint), mirroring availableFor(). */
export function sourcePosition(
    row: StepInput,
    ctx: LotContext | undefined,
    departmentId: number | null,
): string | null {
    if (!ctx) {
        return null;
    }
    const step = resolvedStep(row, ctx, departmentId);
    if (!step) {
        return ctx.floor_position;
    }
    return row.is_reprocess ? step.to_position : step.from_position;
}

/** The answers of a refused save are void once the line changes (lot, step, operation, flags or quantities). */
export function clearOverrides(row: SheetLineRow): void {
    row.override_reason = null;
    row.colour_override_reason = null;
}

/**
 * A lot picked on the row (list or typed): the lot itself and the widths the LOV row carries; the step, the
 * availability and the override answers start over. Returns the model row patched, or undefined when it is gone.
 */
export function applyLotPick(
    rows: SheetLineRow[],
    uid: string | undefined,
    picked: LovRow,
): SheetLineRow | undefined {
    const row = rowByUid(rows, uid);
    if (!row) {
        return undefined;
    }
    Object.assign(row, {
        lot_id: picked.id,
        lot: String(picked.label),
        g_width: (picked.gw_oracle as number | null | undefined) ?? null,
        f_width: (picked.f_width as number | null | undefined) ?? null,
        lot_route_step_id: null,
        available: null,
    });
    clearOverrides(row);
    return row;
}

/**
 * The lot's context arrived: the row takes the step the service would resolve (the department's default step when
 * the row has no operation yet — and that step's operation, with its stretch flag, reprocess kind and finish type),
 * the lot's widths where the LOV row had none, and the metres available at its source.
 */
export function applyLotContext(
    rows: SheetLineRow[],
    uid: string | undefined,
    ctx: LotContext,
    operations: OperationOption[],
    departmentId: number | null,
): SheetLineRow | undefined {
    const row = rowByUid(rows, uid);
    if (!row) {
        return undefined;
    }
    if (row.operation_id === null) {
        const step = ctx.steps.find((s) => s.id === ctx.default_step_id);
        row.lot_route_step_id = step?.id ?? null;
        if (step) {
            const op = operations.find((o) => o.value === step.operation_id);
            Object.assign(row, {
                operation_id: step.operation_id,
                operation: step.operation,
                is_stretch: op?.is_stretch ?? false,
                is_reprocess: reprocessAfterPick(row, op, undefined),
                output_type: row.output_type ?? op?.finish_type ?? null,
            });
        }
    } else {
        row.lot_route_step_id =
            resolvedStep({ ...row, lot_route_step_id: null }, ctx, departmentId)
                ?.id ?? null;
    }
    row.g_width ??= ctx.gw_oracle;
    row.f_width ??= ctx.f_width;
    row.available = availableFor(row, ctx, departmentId);
    return row;
}

/**
 * The reprocess flag after an operation is put on the row: a `reprocess` kind forces it on; leaving a
 * reprocess-kind operation for another kind takes the forced flag off again; otherwise the user's own tick stays.
 */
function reprocessAfterPick(
    row: SheetLineRow,
    op: OperationOption | undefined,
    previous: OperationOption | undefined,
): boolean {
    if (op?.kind === 'reprocess') {
        return true;
    }
    return previous?.kind === 'reprocess' ? false : Boolean(row.is_reprocess);
}

/**
 * An operation picked on the row: its name, stretch flag and default output type; a `reprocess` kind makes the
 * line a reprocess line (and leaving one for another kind unmakes it — `operations` tells the kind of the
 * operation the row had). Unless the row's step already belongs to this operation, the step is resolved again
 * for it (when the lot's context is known); the override answers start over.
 */
export function applyOperationPick(
    rows: SheetLineRow[],
    uid: string | undefined,
    op: OperationOption,
    ctx: LotContext | undefined,
    departmentId: number | null,
    operations: OperationOption[],
): SheetLineRow | undefined {
    const row = rowByUid(rows, uid);
    if (!row) {
        return undefined;
    }
    const previous = operations.find((o) => o.value === row.operation_id);
    Object.assign(row, {
        operation_id: op.value,
        operation: op.label,
        is_stretch: op.is_stretch,
        is_reprocess: reprocessAfterPick(row, op, previous),
        output_type: row.output_type ?? op.finish_type ?? null,
    });
    clearOverrides(row);
    if (ctx) {
        const current = ctx.steps.find((s) => s.id === row.lot_route_step_id);
        if (!current || current.operation_id !== op.value) {
            row.lot_route_step_id =
                resolvedStep(
                    { ...row, lot_route_step_id: null },
                    ctx,
                    departmentId,
                )?.id ?? null;
        }
    }
    row.available = availableFor(row, ctx, departmentId);
    return row;
}

/** The footer's input: complete rows only, an output type defaulting to the operation's finish type like the server. */
export function totalsInput(
    rows: SheetLineRow[],
    operations: OperationOption[],
): TotalsInput[] {
    return rows.filter(isComplete).map((r) => ({
        qty_out: r.qty_out,
        is_reprocess: Boolean(r.is_reprocess),
        is_stretch: Boolean(r.is_stretch),
        output_type:
            r.output_type ??
            operations.find((o) => o.value === r.operation_id)?.finish_type ??
            null,
    }));
}
