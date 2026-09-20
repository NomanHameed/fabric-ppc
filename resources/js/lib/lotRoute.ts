import type { RouteStep, RouteStepRow } from '@/types/production';

/** The grid rows for a lot's steps: the skip flag starts as the saved status. */
export function stepRowsFrom(steps: RouteStep[]): RouteStepRow[] {
    return steps.map((s) => ({ ...s, skipped: s.status === 'skipped' }));
}

/** A blank row the grid appends on Down-arrow: an added step, pending, nothing waiting. */
export function blankStepRow(): RouteStepRow {
    return {
        id: null,
        seq: 0,
        operation_id: null,
        operation: '',
        department: '',
        status: 'pending',
        is_added: true,
        at_position: 0,
        locked: false,
        skipped: false,
    };
}

/** What a save sends: rows without an operation are unfinished blanks and are dropped. */
export function stepPayload(
    rows: RouteStepRow[],
): { id: number | null; operation_id: number; skipped: boolean }[] {
    return rows
        .filter((r) => r.operation_id !== null)
        .map((r) => ({
            id: r.id,
            operation_id: r.operation_id as number,
            skipped: r.skipped,
        }));
}

/** The part of the rows a save changes; equal signatures mean nothing to save. */
export function stepSignature(rows: RouteStepRow[]): string {
    return JSON.stringify(stepPayload(rows));
}

/**
 * The rows after swapping `from` with `to`, or null when the move is refused: out of range, or either row
 * is locked (the service keeps progressed steps where they are, so the grid does not pretend otherwise).
 */
export function swapSteps(
    rows: RouteStepRow[],
    from: number,
    to: number,
): RouteStepRow[] | null {
    if (
        from < 0 ||
        to < 0 ||
        from >= rows.length ||
        to >= rows.length ||
        from === to ||
        rows[from].locked ||
        rows[to].locked
    ) {
        return null;
    }
    const out = [...rows];
    [out[from], out[to]] = [out[to], out[from]];
    return out;
}

/**
 * Locked rows that a grid edit dropped (Ctrl+Del) are put back where they were. Returns the rows unchanged
 * (same reference) when nothing was dropped.
 */
export function restoreLockedRows(
    previous: RouteStepRow[],
    next: RouteStepRow[],
): RouteStepRow[] {
    const present = new Set(
        next.map((r) => r.id).filter((id): id is number => id !== null),
    );
    const missing = previous
        .map((row, index) => ({ row, index }))
        .filter(
            ({ row }) => row.locked && row.id !== null && !present.has(row.id),
        );
    if (missing.length === 0) {
        return next;
    }
    const out = [...next];
    for (const { row, index } of missing) {
        out.splice(Math.min(index, out.length), 0, row);
    }
    return out;
}

/** Row indexes named by server errors keyed `steps.<row>.<field>`. */
export function errorRowIndexes(errors: Record<string, unknown>): Set<number> {
    const out = new Set<number>();
    for (const key of Object.keys(errors)) {
        const m = /^steps\.(\d+)\./.exec(key);
        if (m) {
            out.add(Number(m[1]));
        }
    }
    return out;
}
