import type { ColumnDef } from '@/lib/agGrid';
import { formatQty } from '@/lib/dates';
import { t } from '@/lib/i18n';
import type { FlatLotGridRow, FlatLotRow } from '@/types/production';

/**
 * The flat, Oracle-shaped lot payload's **visible columns**, in one place.
 *
 * `ProductionAnalyticsService::flatLots()` assembles the payload once for both readers of it — the Lots
 * screen's flat grid and R1, the Month Wise Fabric Report — so the columns over it are declared once too.
 * The Lots screen turns these into AG Grid column definitions (`flatLotGridColumns()`); the report draws the
 * same 21 columns as an HTML table, because a report has group headers and subtotal rows interleaved with
 * its rows and AG Grid Community cannot do that. Both read this list, so the mill never sees its own fields
 * in two different orders.
 *
 * The order is the payload's own (`FLAT_KEYS`), with one deliberate move: `status_oracle` sits beside
 * `stage_name`, because the raw status and the reporting stage it resolves to are read together — the stage
 * never replaces the status. The keys the payload also carries but that are not columns are the three ids
 * (`stage_id`, `lot_id`) and the two vestigial stage-process keys, which have no column behind them since
 * resolution became stage-only.
 */

/**
 * What a column holds, which decides its alignment and its formatting:
 *
 *  - `text` — a name, a code, a pre-formatted dd-mm-yyyy date. Leading edge.
 *  - `id` — a whole-number identifier (PO number, party code). Trailing edge, tabular, but not metres.
 *  - `qty` — metres, fixed 2 dp. Trailing edge, tabular; a null stays blank, because an unmeasured width is
 *    not a width of zero.
 */
export type FlatLotColumnKind = 'text' | 'id' | 'qty';

export type FlatLotColumnSpec = {
    field: keyof FlatLotRow;
    kind: FlatLotColumnKind;
    width?: number;
    flex?: number;
    minWidth?: number;
    cellClass?: string;
    pinned?: 'left';
};

export const FLAT_LOT_COLUMNS: readonly FlatLotColumnSpec[] = [
    {
        field: 'lot_no_oracle',
        kind: 'text',
        width: 120,
        cellClass: 'tabular-nums',
        pinned: 'left',
    },
    { field: 'po_no', kind: 'id', width: 100 },
    { field: 'ref', kind: 'text', width: 110 },
    { field: 'party_oracle_id', kind: 'id', width: 110 },
    { field: 'party_oracle_name', kind: 'text', flex: 2, minWidth: 160 },
    {
        field: 'date_oracle',
        kind: 'text',
        width: 110,
        cellClass: 'tabular-nums',
    },
    { field: 'design_oracle_id', kind: 'text', width: 120 },
    { field: 'color_oracle_id', kind: 'text', width: 120 },
    { field: 'color_oracle_name', kind: 'text', flex: 1, minWidth: 140 },
    { field: 'clr_status_oracle', kind: 'text', width: 130 },
    { field: 'po_type_oracle', kind: 'text', width: 110 },
    { field: 'quality', kind: 'text', flex: 2, minWidth: 180 },
    { field: 'gw_oracle', kind: 'qty', width: 90 },
    // the raw status beside the stage it resolves to — the stage never replaces the status
    {
        field: 'status_oracle',
        kind: 'text',
        width: 130,
        cellClass: 'font-mono',
    },
    { field: 'stage_name', kind: 'text', width: 150 },
    { field: 'greige_received_oracle', kind: 'qty', width: 140 },
    { field: 'greige_in_stock_oracle', kind: 'qty', width: 140 },
    { field: 'qty_issued', kind: 'qty', width: 140 },
    { field: 'wip', kind: 'qty', width: 140 },
    { field: 'finished', kind: 'qty', width: 140 },
    { field: 'dispatch', kind: 'qty', width: 140 },
] as const;

/**
 * The report's six quantities (spec §7.1 / §14), in the order they are printed.
 *
 * One spelling now does for both: the physical rename put the mill's own field names on `lots`, so a flat
 * row's key and the key the stage totals use for the same figure are the same string. This list is still the
 * single place they are declared, so a total always sits under the column it totals.
 */
export type FlatLotTotalField =
    | 'greige_received_oracle'
    | 'greige_in_stock_oracle'
    | 'qty_issued'
    | 'wip'
    | 'finished'
    | 'dispatch';

export const FLAT_LOT_TOTAL_FIELDS: readonly FlatLotTotalField[] = [
    'greige_received_oracle',
    'greige_in_stock_oracle',
    'qty_issued',
    'wip',
    'finished',
    'dispatch',
] as const;

/** Every header goes through `t()` in the `production.flat` group — the mill's own field names. */
export function flatLotHeader(field: keyof FlatLotRow): string {
    return t(`production.flat.${field}`);
}

/** Fixed 2-dp metres, but a null stays blank: an unmeasured width is not a width of zero. */
export const flatQtyFormatter = (p: { value: unknown }): string =>
    p.value === null || p.value === undefined ? '' : formatQty(p.value);

/** The leading / trailing edge a column's values sit against, as a logical-property class (RTL-safe). */
export function flatLotAlignClass(kind: FlatLotColumnKind): string {
    return kind === 'text' ? 'text-start' : 'text-end tabular-nums';
}

/** One cell as plain text, for the report's HTML table. A null is blank, never `0` or `null`. */
export function flatLotCell(row: FlatLotRow, spec: FlatLotColumnSpec): string {
    const value = row[spec.field];
    if (value === null || value === undefined) {
        return '';
    }
    return spec.kind === 'qty' ? formatQty(value) : String(value);
}

/** The same columns as AG Grid definitions, for the Lots screen's flat grid. */
export function flatLotGridColumns(): ColumnDef<FlatLotGridRow>[] {
    return FLAT_LOT_COLUMNS.map((spec) => {
        const column: ColumnDef<FlatLotGridRow> = {
            field: spec.field,
            headerName: flatLotHeader(spec.field),
        };
        if (spec.width !== undefined) {
            column.width = spec.width;
        }
        if (spec.flex !== undefined) {
            column.flex = spec.flex;
        }
        if (spec.minWidth !== undefined) {
            column.minWidth = spec.minWidth;
        }
        if (spec.pinned !== undefined) {
            column.pinned = spec.pinned;
        }
        if (spec.kind === 'text') {
            if (spec.cellClass !== undefined) {
                column.cellClass = spec.cellClass;
            }
            return column;
        }
        column.type = 'numericColumn';
        column.cellClass = 'text-end tabular-nums';
        if (spec.kind === 'qty') {
            column.valueFormatter = flatQtyFormatter;
        }
        return column;
    });
}
