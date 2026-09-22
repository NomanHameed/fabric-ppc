export type TotalsInput = {
    qty_out: number | string | null;
    is_reprocess: boolean;
    is_stretch: boolean;
    output_type: 'dyed' | 'print' | null;
};
export type Buckets = {
    fresh: number;
    reprocess: number;
    stretching: number;
    total: number;
};
export type SheetTotals = {
    all: Buckets;
    dyed: Buckets;
    print: Buckets;
    other: Buckets;
};

export const TOTAL_BUCKETS = [
    'fresh',
    'reprocess',
    'stretching',
    'total',
] as const;
export const TOTAL_GROUPS = ['all', 'dyed', 'print', 'other'] as const;

const round2 = (n: number): number => Math.round(n * 100) / 100;
const empty = (): Buckets => ({
    fresh: 0,
    reprocess: 0,
    stretching: 0,
    total: 0,
});

/**
 * Mirrors ProductionSheetService::totalsOf(): metres out per bucket (a reprocess line is reprocess whatever its
 * operation; a stretch operation is stretching, not fresh) for the whole sheet and per output type, rounded to
 * 2 dp at every addition like the server. Rows without a usable quantity are skipped.
 */
export function sheetTotals(rows: TotalsInput[]): SheetTotals {
    const out: SheetTotals = {
        all: empty(),
        dyed: empty(),
        print: empty(),
        other: empty(),
    };
    for (const r of rows) {
        if (r.qty_out === null || r.qty_out === '') {
            continue;
        }
        const qty = Number(r.qty_out);
        if (!Number.isFinite(qty)) {
            continue;
        }
        const bucket: keyof Buckets = r.is_reprocess
            ? 'reprocess'
            : r.is_stretch
              ? 'stretching'
              : 'fresh';
        for (const k of ['all', r.output_type ?? 'other'] as const) {
            out[k][bucket] = round2(out[k][bucket] + qty);
            out[k].total = round2(out[k].total + qty);
        }
    }
    return out;
}
