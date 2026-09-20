import { describe, expect, it } from 'vitest';
import {
    blankStepRow,
    errorRowIndexes,
    restoreLockedRows,
    stepPayload,
    stepRowsFrom,
    stepSignature,
    swapSteps,
} from '@/lib/lotRoute';
import type { RouteStep, RouteStepRow } from '@/types/production';

function step(over: Partial<RouteStep> & { id: number }): RouteStep {
    return {
        seq: over.id * 10,
        operation_id: over.id,
        operation: `OP ${over.id}`,
        department: 'DEPT',
        status: 'pending',
        is_added: false,
        at_position: 0,
        locked: false,
        ...over,
    };
}

const steps: RouteStep[] = [
    step({ id: 1, status: 'done', locked: true }),
    step({ id: 2, status: 'skipped' }),
    step({ id: 3 }),
];

describe('stepRowsFrom / stepPayload / stepSignature', () => {
    it('seeds the skip flag from the saved status and keeps everything else', () => {
        const rows = stepRowsFrom(steps);
        expect(rows.map((r) => r.skipped)).toEqual([false, true, false]);
        expect(rows[0].locked).toBe(true);
    });

    it('sends id, operation and skip flag, dropping blank rows', () => {
        const rows: RouteStepRow[] = [
            ...stepRowsFrom(steps),
            blankStepRow(),
            { ...blankStepRow(), operation_id: 9, operation: 'OP 9' },
        ];
        expect(stepPayload(rows)).toEqual([
            { id: 1, operation_id: 1, skipped: false },
            { id: 2, operation_id: 2, skipped: true },
            { id: 3, operation_id: 3, skipped: false },
            { id: null, operation_id: 9, skipped: false },
        ]);
    });

    it('signatures change only with what a save sends', () => {
        const rows = stepRowsFrom(steps);
        const base = stepSignature(rows);
        expect(stepSignature([...rows, blankStepRow()])).toBe(base);
        expect(
            stepSignature(rows.map((r) => ({ ...r, department: 'X' }))),
        ).toBe(base);
        const toggled = rows.map((r, i) =>
            i === 2 ? { ...r, skipped: true } : r,
        );
        expect(stepSignature(toggled)).not.toBe(base);
        expect(stepSignature([rows[0], rows[2], rows[1]])).not.toBe(base);
    });
});

describe('swapSteps', () => {
    const rows = stepRowsFrom(steps);

    it('swaps two free rows', () => {
        const out = swapSteps(rows, 1, 2);
        expect(out?.map((r) => r.id)).toEqual([1, 3, 2]);
        expect(rows.map((r) => r.id)).toEqual([1, 2, 3]);
    });

    it('refuses a move involving a locked row or out of range', () => {
        expect(swapSteps(rows, 0, 1)).toBeNull();
        expect(swapSteps(rows, 1, 0)).toBeNull();
        expect(swapSteps(rows, 2, 3)).toBeNull();
        expect(swapSteps(rows, -1, 0)).toBeNull();
        expect(swapSteps(rows, 1, 1)).toBeNull();
    });
});

describe('restoreLockedRows', () => {
    const rows = stepRowsFrom([
        step({ id: 1 }),
        step({ id: 2, status: 'done', locked: true }),
        step({ id: 3 }),
    ]);

    it('returns the same rows when no locked row was dropped', () => {
        const kept = [rows[0], rows[1]];
        expect(restoreLockedRows(rows, kept)).toBe(kept);
        const reordered = [rows[2], rows[1], rows[0]];
        expect(restoreLockedRows(rows, reordered)).toBe(reordered);
    });

    it('puts a dropped locked row back where it was', () => {
        expect(
            restoreLockedRows(rows, [rows[0], rows[2]]).map((r) => r.id),
        ).toEqual([1, 2, 3]);
        expect(restoreLockedRows(rows, []).map((r) => r.id)).toEqual([2]);
        expect(
            restoreLockedRows(rows, [rows[2], blankStepRow()]).map((r) => r.id),
        ).toEqual([3, 2, null]);
    });
});

describe('errorRowIndexes', () => {
    it('reads row numbers out of steps.<row>.<field> keys only', () => {
        expect(
            errorRowIndexes({
                'steps.2.skipped': 'no',
                'steps.0.operation_id': 'no',
                steps: 'no',
                'steps.x.skipped': 'no',
                route_template_id: 'no',
            }),
        ).toEqual(new Set([0, 2]));
        expect(errorRowIndexes({})).toEqual(new Set());
    });
});
