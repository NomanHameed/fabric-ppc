import { describe, expect, it } from 'vitest';
import {
    applyOperationPick,
    blankLineRow,
    totalsInput,
} from '@/lib/productionSheet';
import { sheetTotals } from '@/lib/sheetTotals';

describe('sheetTotals', () => {
    it('buckets fresh / reprocess / stretching per output type like the service', () => {
        // the figures of the Pest totals test: 610 fresh, 100 reprocess, 52 stretching = 762; dyed 610
        const totals = sheetTotals([
            {
                qty_out: 500,
                is_reprocess: false,
                is_stretch: false,
                output_type: 'dyed',
            },
            {
                qty_out: '110',
                is_reprocess: false,
                is_stretch: false,
                output_type: 'dyed',
            },
            {
                qty_out: 100,
                is_reprocess: true,
                is_stretch: true,
                output_type: 'print',
            },
            {
                qty_out: 52,
                is_reprocess: false,
                is_stretch: true,
                output_type: null,
            },
        ]);
        expect(totals.all).toEqual({
            fresh: 610,
            reprocess: 100,
            stretching: 52,
            total: 762,
        });
        expect(totals.dyed).toEqual({
            fresh: 610,
            reprocess: 0,
            stretching: 0,
            total: 610,
        });
        expect(totals.print).toEqual({
            fresh: 0,
            reprocess: 100,
            stretching: 0,
            total: 100,
        });
        expect(totals.other).toEqual({
            fresh: 0,
            reprocess: 0,
            stretching: 52,
            total: 52,
        });
    });

    it('rounds to 2 dp and skips rows without a quantity', () => {
        const totals = sheetTotals([
            {
                qty_out: 0.1,
                is_reprocess: false,
                is_stretch: false,
                output_type: null,
            },
            {
                qty_out: 0.2,
                is_reprocess: false,
                is_stretch: false,
                output_type: null,
            },
            {
                qty_out: null,
                is_reprocess: false,
                is_stretch: false,
                output_type: null,
            },
            {
                qty_out: '',
                is_reprocess: false,
                is_stretch: false,
                output_type: null,
            },
            {
                qty_out: 'abc',
                is_reprocess: false,
                is_stretch: false,
                output_type: null,
            },
        ]);
        expect(totals.all.fresh).toBe(0.3);
        expect(totals.all.total).toBe(0.3);
        expect(totals.other.total).toBe(0.3);
        expect(sheetTotals([]).all.total).toBe(0);
    });

    it('counts a line of a reprocess-kind operation as reprocess, whatever the checkbox said', () => {
        const rows = [{ ...blankLineRow(), lot_id: 1, lot: 'L', qty_out: 30 }];
        applyOperationPick(
            rows,
            rows[0]._uid,
            {
                value: 4,
                label: 'RE-SINGE',
                department_id: 2,
                kind: 'reprocess',
                is_stretch: true,
                finish_type: 'dyed',
            },
            undefined,
            2,
            [],
        );
        expect(rows[0].is_reprocess).toBe(true);
        const totals = sheetTotals(totalsInput(rows, []));
        expect(totals.all).toEqual({
            fresh: 0,
            reprocess: 30,
            stretching: 0,
            total: 30,
        });
        expect(totals.dyed.reprocess).toBe(30); // the operation's finish type stands in for a blank output type
    });
});
