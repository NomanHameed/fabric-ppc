import { describe, expect, it } from 'vitest';
import { setTranslations } from '@/lib/i18n';
import {
    applyLotContext,
    applyLotPick,
    applyOperationPick,
    availableFor,
    blankLineRow,
    departmentSteps,
    incompleteRowIndexes,
    lineErrorText,
    lineErrors,
    linePayload,
    lineRowsFrom,
    lineSignature,
    overrideToAsk,
    resolvedStep,
    rowByUid,
    sourcePosition,
    stepLabel,
    totalsInput,
} from '@/lib/productionSheet';
import type {
    LotContext,
    OperationOption,
    SheetLineRow,
} from '@/types/production';

function row(o: Partial<SheetLineRow>): SheetLineRow {
    return { ...blankLineRow(), ...o };
}

const operations: OperationOption[] = [
    {
        value: 1,
        label: 'SINGING',
        department_id: 2,
        kind: 'process',
        is_stretch: false,
        finish_type: null,
    },
    {
        value: 2,
        label: 'BLEACH',
        department_id: 3,
        kind: 'process',
        is_stretch: false,
        finish_type: null,
    },
    {
        value: 3,
        label: 'DRY',
        department_id: 2,
        kind: 'process',
        is_stretch: false,
        finish_type: null,
    },
    {
        value: 4,
        label: 'RE-SINGE',
        department_id: 2,
        kind: 'reprocess',
        is_stretch: false,
        finish_type: null,
    },
    {
        value: 5,
        label: 'STENTER',
        department_id: 2,
        kind: 'process',
        is_stretch: true,
        finish_type: 'dyed',
    },
];

const ctx: LotContext = {
    lot_id: 1,
    lot_no_oracle: '260123',
    po_no: 5,
    party: 'P',
    quality: null,
    design: null,
    color_oracle_name: null,
    po_type_oracle: null,
    state: 'open',
    status_oracle: null,
    clr_status_oracle: null,
    colour_approved: false,
    balances: { ISSUED: 100, 'STEP:10': 40 },
    gw_oracle: null,
    f_width: null,
    default_step_id: 10,
    next_step_id: 10,
    floor_position: 'ISSUED',
    floor_available: 100,
    steps: [
        {
            id: 10,
            seq: 1,
            operation_id: 1,
            operation: 'SINGING',
            department_id: 2,
            department: 'Singeing',
            status: 'partial',
            is_added: false,
            from_position: 'ISSUED',
            to_position: 'STEP:10',
            available: 100,
        },
        {
            id: 11,
            seq: 2,
            operation_id: 2,
            operation: 'BLEACH',
            department_id: 3,
            department: 'Bleaching',
            status: 'pending',
            is_added: false,
            from_position: 'STEP:10',
            to_position: 'STEP:11',
            available: 40,
        },
        {
            id: 12,
            seq: 3,
            operation_id: 3,
            operation: 'DRY',
            department_id: 2,
            department: 'Singeing',
            status: 'skipped',
            is_added: false,
            from_position: null,
            to_position: null,
            available: 0,
        },
    ],
};

describe('productionSheet rows', () => {
    it('sends only complete rows, cast the way the service takes them', () => {
        const rows = [
            row({
                lot_id: 1,
                lot: 'x',
                operation_id: 2,
                qty_in: 10,
                qty_out: 9.5,
                batch_no: ' B1 ',
                is_reprocess: true,
                reprocess_reason: 'SHADE',
            }),
            row({}), // blank
            row({ lot: '1234' }), // typed, not resolved
            row({
                id: 7,
                lot_id: 3,
                lot: 'y',
                operation_id: 4,
                qty_in: '5' as unknown as number,
                output_type: null,
                reprocess_reason: 'STALE',
                remarks: '  ',
            }),
        ];
        const payload = linePayload(rows);
        expect(payload).toHaveLength(2);
        expect(payload[0]).not.toHaveProperty('_uid');
        expect(payload[0]).toMatchObject({
            id: null,
            lot_id: 1,
            operation_id: 2,
            qty_in: 10,
            qty_out: 9.5,
            batch_no: 'B1',
            is_reprocess: true,
            reprocess_reason: 'SHADE',
        });
        expect(payload[1]).toMatchObject({
            id: 7,
            lot_id: 3,
            qty_in: 5,
            qty_out: null,
            reprocess_reason: null,
            remarks: null,
            output_type: null,
        });
        expect(incompleteRowIndexes(rows)).toEqual([2]);
    });

    it('signature ignores the override answers and uids, counts incomplete rows, and lineRowsFrom adds the screen-only fields', () => {
        const a = [row({ lot_id: 1, lot: 'x', operation_id: 2, qty_in: 1 })];
        // a blank row appended by the grid is not an edit; a typed lot that is not resolved yet is
        expect(lineSignature([...a, row({})])).toBe(lineSignature(a));
        expect(lineSignature([...a, row({ lot: '1234' })])).not.toBe(
            lineSignature(a),
        );
        expect(lineSignature([row({ lot: '1234' })])).not.toBe(
            lineSignature([]),
        );
        const b = [
            row({
                lot_id: 1,
                lot: 'x',
                operation_id: 2,
                qty_in: 1,
                override_reason: 'short',
                colour_override_reason: 'ok',
            }),
        ];
        expect(lineSignature(a)).toBe(lineSignature(b));
        expect(lineSignature(a)).not.toBe(
            lineSignature([
                row({ lot_id: 1, lot: 'x', operation_id: 2, qty_in: 2 }),
            ]),
        );
        const loaded = lineRowsFrom([
            {
                id: 1,
                batch_no: null,
                lot_id: 1,
                lot: 'L',
                lot_route_step_id: null,
                operation_id: 2,
                operation: 'O',
                is_stretch: false,
                is_reprocess: false,
                reprocess_reason: null,
                qty_in: 1,
                qty_out: 1,
                output_type: null,
                g_width: null,
                f_width: null,
                remarks: null,
                colour_override: null,
            },
        ]);
        expect(loaded[0]).toMatchObject({
            available: null,
            override_reason: null,
            colour_override_reason: null,
            lot: 'L',
        });
        expect(typeof loaded[0]._uid).toBe('string');
        expect(blankLineRow()._uid).not.toBe(blankLineRow()._uid);
        expect(rowByUid(loaded, loaded[0]._uid)).toBe(loaded[0]);
        expect(rowByUid(loaded, undefined)).toBeUndefined();
        expect(rowByUid(loaded, 'nope')).toBeUndefined();
    });

    it('maps server errors keyed by payload row back to grid rows', () => {
        setTranslations('en', 'ltr', {
            production: { sheet: { line_error: 'Line :n: :message' } },
        });
        const rows = [
            row({}), // blank: no payload row
            row({ lot_id: 1, lot: 'a', operation_id: 1 }), // payload 0
            row({ lot: 'typed' }), // incomplete: no payload row
            row({ lot_id: 2, lot: 'b', operation_id: 1 }), // payload 1
        ];
        const errors = lineErrors(
            {
                'lines.1.qty_in': 'Lot b has only 10 m',
                'lines.0.colour_override_reason': ['Colour not approved'],
                machine_id: 'x',
                'lines.9.qty_in': 'gone',
            },
            rows,
        );
        expect(errors).toEqual([
            {
                row: 1,
                field: 'colour_override_reason',
                message: 'Colour not approved',
            },
            { row: 3, field: 'qty_in', message: 'Lot b has only 10 m' },
        ]);
        expect(lineErrorText(errors[1])).toBe('Line 4: Lot b has only 10 m');
    });

    it('resolves the steps of a department and what a line can draw on', () => {
        setTranslations('en', 'ltr', {
            production: {
                sheet: { step_label: ':seq. :operation (:status)' },
                step_status: { partial: 'Partial', pending: 'Pending' },
            },
        });
        expect(departmentSteps(ctx, 2).map((s) => s.id)).toEqual([10]); // the skipped step is out
        expect(departmentSteps(ctx, null).map((s) => s.id)).toEqual([10, 11]);
        expect(departmentSteps(undefined, 2)).toEqual([]);
        expect(stepLabel(ctx.steps[0])).toBe('1. SINGING (Partial)');

        const planned = (step: number | null, op = 1) => ({
            lot_route_step_id: step,
            is_reprocess: false,
            operation_id: op,
        });
        const reprocess = (step: number | null, op = 1) => ({
            lot_route_step_id: step,
            is_reprocess: true,
            operation_id: op,
        });
        expect(availableFor(planned(10), ctx, 2)).toBe(100);
        expect(availableFor(reprocess(10), ctx, 2)).toBe(40); // in place at the step's target
        expect(availableFor(reprocess(11), ctx, 3)).toBe(0); // nothing at STEP:11 yet
        expect(availableFor(planned(10), undefined, 2)).toBeNull();
        expect(sourcePosition(planned(10), ctx, 2)).toBe('ISSUED');
        expect(sourcePosition(reprocess(10), ctx, 2)).toBe('STEP:10');
    });

    it('resolves a null step the way the service does (automatic)', () => {
        const planned = (op: number) => ({
            lot_route_step_id: null,
            is_reprocess: false,
            operation_id: op,
        });
        const reprocess = (op: number) => ({
            lot_route_step_id: null,
            is_reprocess: true,
            operation_id: op,
        });
        // planned: the pending step of the operation in the department
        expect(resolvedStep(planned(1), ctx, 2)?.id).toBe(10);
        expect(availableFor(planned(1), ctx, 2)).toBe(100);
        expect(sourcePosition(planned(1), ctx, 2)).toBe('ISSUED');
        // an operation with no step of its own: the department's next pending step stands in
        expect(resolvedStep(planned(5), ctx, 2)?.id).toBe(10);
        // nothing pending for the department: the operation's last step (a repeat of finished work)
        const done: LotContext = {
            ...ctx,
            steps: ctx.steps.map((s) =>
                s.id === 10 ? { ...s, status: 'done', available: 0 } : s,
            ),
        };
        expect(resolvedStep(planned(1), done, 2)?.id).toBe(10);
        expect(availableFor(planned(1), done, 2)).toBe(0);
        // no step of the operation either: in place at the floor position
        expect(resolvedStep(planned(5), done, 2)).toBeNull();
        expect(availableFor(planned(5), done, 2)).toBe(100);
        expect(sourcePosition(planned(5), done, 2)).toBe('ISSUED');
        // another department: its own steps only
        expect(resolvedStep(planned(2), ctx, 3)?.id).toBe(11);
        expect(availableFor(planned(2), ctx, 3)).toBe(40);
        // reprocess: the department step holding the most metres at its target, else the floor
        expect(resolvedStep(reprocess(4), ctx, 2)?.id).toBe(10);
        expect(availableFor(reprocess(4), ctx, 2)).toBe(40);
        expect(sourcePosition(reprocess(4), ctx, 2)).toBe('STEP:10');
        expect(resolvedStep(reprocess(2), ctx, 3)).toBeNull(); // nothing at STEP:11
        expect(availableFor(reprocess(2), ctx, 3)).toBe(100);
        const twoTargets: LotContext = {
            ...ctx,
            balances: { 'STEP:10': 5, 'STEP:12': 60 },
            steps: ctx.steps.map((s) =>
                s.id === 12
                    ? { ...s, status: 'done', to_position: 'STEP:12' }
                    : s,
            ),
        };
        expect(resolvedStep(reprocess(4), twoTargets, 2)?.id).toBe(12);
        // a skipped step is never resolved to
        expect(resolvedStep(planned(3), ctx, 2)?.id).toBe(10);
    });
});

describe('productionSheet row patches', () => {
    const lotRow = {
        id: 1,
        code: '260123',
        label: '260123 · 5',
        gw_oracle: 60,
    };

    it('applyLotPick fills the lot and resets the step, availability and override answers', () => {
        const rows = [
            row({
                override_reason: 'x',
                lot_route_step_id: 99,
                available: 7,
                f_width: 58,
            }),
            row({}),
        ];
        const uid = rows[0]._uid;
        const patched = applyLotPick(rows, uid, lotRow);
        expect(patched).toBe(rows[0]);
        expect(rows[0]).toMatchObject({
            lot_id: 1,
            lot: '260123 · 5',
            g_width: 60,
            f_width: null,
            lot_route_step_id: null,
            available: null,
            override_reason: null,
            colour_override_reason: null,
        });
        expect(rows[1].lot_id).toBeNull();
        expect(applyLotPick(rows, 'gone', lotRow)).toBeUndefined();
    });

    it('applyLotContext takes the default step and its operation, the widths and the availability', () => {
        const rows = [row({ lot_id: 1, lot: 'L' })];
        const uid = rows[0]._uid;
        applyLotContext(rows, uid, { ...ctx, f_width: 57 }, operations, 2);
        expect(rows[0]).toMatchObject({
            lot_route_step_id: 10,
            operation_id: 1,
            operation: 'SINGING',
            is_stretch: false,
            is_reprocess: false,
            available: 100,
            f_width: 57,
            g_width: null,
        });
        // a reprocess-kind default operation makes it a reprocess line
        const reCtx: LotContext = {
            ...ctx,
            steps: ctx.steps.map((s) =>
                s.id === 10
                    ? { ...s, operation_id: 4, operation: 'RE-SINGE' }
                    : s,
            ),
        };
        const re = [row({ lot_id: 1, lot: 'L' })];
        applyLotContext(re, re[0]._uid, reCtx, operations, 2);
        expect(re[0]).toMatchObject({
            operation_id: 4,
            is_reprocess: true,
            lot_route_step_id: 10,
            available: 40,
        });
        // an operation already on the row keeps it: the step is resolved for that operation
        const kept = [
            row({
                lot_id: 1,
                lot: 'L',
                operation_id: 5,
                operation: 'STENTER',
                is_stretch: true,
            }),
        ];
        applyLotContext(kept, kept[0]._uid, ctx, operations, 2);
        expect(kept[0]).toMatchObject({
            operation_id: 5,
            lot_route_step_id: 10,
            available: 100,
        });
        // no default step (nothing pending here): automatic, at the floor
        const none = [row({ lot_id: 1, lot: 'L' })];
        applyLotContext(
            none,
            none[0]._uid,
            { ...ctx, default_step_id: null },
            operations,
            2,
        );
        expect(none[0]).toMatchObject({
            lot_route_step_id: null,
            operation_id: null,
            available: 100,
        });
        expect(
            applyLotContext(rows, 'gone', ctx, operations, 2),
        ).toBeUndefined();
    });

    it('applyOperationPick sets the operation, its flags and a step for it, and voids the override answers', () => {
        const rows = [
            row({
                lot_id: 1,
                lot: 'L',
                lot_route_step_id: 10,
                override_reason: 'short',
            }),
        ];
        const uid = rows[0]._uid;
        applyOperationPick(rows, uid, operations[3], ctx, 2, operations); // kind = reprocess
        expect(rows[0]).toMatchObject({
            operation_id: 4,
            operation: 'RE-SINGE',
            is_reprocess: true,
            is_stretch: false,
            output_type: null,
            override_reason: null,
            lot_route_step_id: 10, // the reprocess step with metres at its target
            available: 40,
        });
        // a stretch operation with a finish type: the output type defaults to it, an explicit one stays
        applyOperationPick(rows, uid, operations[4], ctx, 2, operations);
        expect(rows[0]).toMatchObject({
            operation_id: 5,
            is_stretch: true,
            output_type: 'dyed',
            is_reprocess: false, // the flag the reprocess kind forced goes with it
        });
        const typed = [row({ lot_id: 1, lot: 'L', output_type: 'print' })];
        applyOperationPick(
            typed,
            typed[0]._uid,
            operations[4],
            ctx,
            2,
            operations,
        );
        expect(typed[0]).toMatchObject({
            output_type: 'print',
            is_reprocess: false,
            lot_route_step_id: 10,
            available: 100,
        });
        // without the lot's context the step and availability wait for it
        const noCtx = [row({})];
        applyOperationPick(
            noCtx,
            noCtx[0]._uid,
            operations[0],
            undefined,
            2,
            operations,
        );
        expect(noCtx[0]).toMatchObject({
            operation_id: 1,
            lot_route_step_id: null,
            available: null,
        });
        // a step that already belongs to the picked operation is kept
        const own = [row({ lot_id: 1, lot: 'L', lot_route_step_id: 11 })];
        applyOperationPick(own, own[0]._uid, operations[1], ctx, 3, operations);
        expect(own[0].lot_route_step_id).toBe(11);
        expect(
            applyOperationPick(rows, 'gone', operations[0], ctx, 2, operations),
        ).toBeUndefined();
    });

    it('applyOperationPick forces the reprocess flag for a reprocess kind, takes it off again when the row leaves that kind, and keeps a manual tick', () => {
        const rows = [row({ lot_id: 1, lot: 'L' })];
        const uid = rows[0]._uid;
        applyOperationPick(rows, uid, operations[3], ctx, 2, operations); // kind = reprocess
        expect(rows[0].is_reprocess).toBe(true);
        applyOperationPick(rows, uid, operations[0], ctx, 2, operations); // kind = process
        expect(rows[0].is_reprocess).toBe(false);
        // the user's own tick survives a process-kind pick
        rows[0].is_reprocess = true;
        applyOperationPick(rows, uid, operations[1], ctx, 2, operations);
        expect(rows[0].is_reprocess).toBe(true);
        // an untouched row picking a process kind stays off
        const plain = [row({ lot_id: 1, lot: 'L' })];
        applyOperationPick(
            plain,
            plain[0]._uid,
            operations[0],
            ctx,
            2,
            operations,
        );
        expect(plain[0].is_reprocess).toBe(false);
    });

    it('totalsInput feeds the footer complete rows only, with the operation finish type by default', () => {
        const input = totalsInput(
            [
                row({ lot_id: 1, operation_id: 5, qty_out: 10 }), // STENTER: stretch, dyed
                row({
                    lot_id: 1,
                    operation_id: 4,
                    qty_out: 5,
                    is_reprocess: true,
                    output_type: 'print',
                }),
                row({ lot: 'typed', qty_out: 99 }), // incomplete: not counted
                row({ operation_id: 1, qty_out: 99 }), // no lot: not counted
            ],
            operations,
        );
        expect(input).toEqual([
            {
                qty_out: 10,
                is_reprocess: false,
                is_stretch: false,
                output_type: 'dyed',
            },
            {
                qty_out: 5,
                is_reprocess: true,
                is_stretch: false,
                output_type: 'print',
            },
        ]);
    });

    it('overrideToAsk prompts only on the server hint, and only once per row', () => {
        setTranslations('en', 'ltr', {
            production: { sheet: { line_error: 'Line :n: :message' } },
        });
        const rows = [
            row({ lot_id: 1, lot: 'a', operation_id: 1, qty_in: 150 }),
            row({ lot_id: 2, lot: 'b', operation_id: 1, qty_in: 150 }),
        ];
        // no hint (a user without production.override): nothing to ask, whatever the message
        expect(
            overrideToAsk(
                lineErrors({ 'lines.0.qty_in': 'Lot a has only 100 m' }, rows),
                rows,
                true,
            ),
        ).toBeNull();
        const errs = lineErrors(
            {
                'lines.1.qty_in': 'Lot b has only 100 m',
                'lines.1.override_reason': 'You may override.',
            },
            rows,
        );
        expect(overrideToAsk(errs, rows, false)).toEqual({
            kind: 'qty',
            error: {
                row: 1,
                field: 'override_reason',
                message: 'You may override.',
            },
            message: 'Line 2: Lot b has only 100 m',
        });
        rows[1].override_reason = 'given';
        expect(overrideToAsk(errs, rows, true)).toBeNull();
        // the colour gate asks only a user who may approve, and not again once answered
        const colour = lineErrors(
            { 'lines.0.colour_override_reason': 'Colour not approved' },
            rows,
        );
        expect(overrideToAsk(colour, rows, false)).toBeNull();
        expect(overrideToAsk(colour, rows, true)).toMatchObject({
            kind: 'colour',
            error: { row: 0 },
            message: 'Line 1: Colour not approved',
        });
        rows[0].colour_override_reason = 'ok';
        expect(overrideToAsk(colour, rows, true)).toBeNull();
    });
});
