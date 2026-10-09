export type LotState = 'open' | 'hold' | 'closed' | 'cancelled';

/** A row of the `lots` LOV (LotLov::row): the columns shown plus what a picking screen fills from it. */
export type LotSummary = {
    id: number;
    code: string;
    label: string;
    po_no: number;
    party: string;
    design: string | null;
    color_oracle_name: string | null;
    quality: string;
    gw_oracle: number | null;
    f_width: number | null;
    greige_in_stock_oracle: number;
    wip: number;
    finished: number;
    state: LotState;
    status_oracle: string | null;
    contract_id: number;
    stage_id: number | null;
    stage: string | null;
};

/** A row of the `contracts` LOV (ContractLov::row): open lots with greige in stock, and the open lots' stock in metres. */
export type ContractSummary = {
    id: number;
    code: string;
    label: string;
    ref_no: string | null;
    party: string;
    open_lots: number;
    greige_in_stock_oracle: number;
};

/** One `issue` movement as the Issue to Floor screen lists it (IssueController::index). */
export type IssueRow = {
    id: number;
    lot_id: number;
    lot_no_oracle: string;
    po_no: number;
    party: string;
    design: string | null;
    color_oracle_name: string | null;
    quality: string | null;
    greige_in_stock_oracle: number;
    qty: number;
    reversal: boolean;
    moved_at: string;
    user: string | null;
    remarks: string | null;
};

export type RouteStepStatus = 'pending' | 'partial' | 'done' | 'skipped';

/** One step of a lot's route as the Lot Route screen receives it (LotRouteController::index). */
export type RouteStep = {
    id: number | null;
    seq: number;
    operation_id: number | null;
    operation: string;
    department: string;
    status: RouteStepStatus;
    is_added: boolean;
    /** Metres that completed this step and wait after it (the STEP:<id> balance). */
    at_position: number;
    /** Production or metres waiting: the service refuses to remove, move, skip or re-operate it. */
    locked: boolean;
};

/** A route step as the editor's grid holds it: the skip flag is the edit, `status` stays as loaded. */
export type RouteStepRow = RouteStep & { skipped: boolean };

/** The lot header of the Lot Route screen (LotRouteController::index). */
export type RouteLot = {
    id: number;
    lot_no_oracle: string;
    po_no: number;
    party: string;
    contract_id: number;
    quality: string | null;
    design: string | null;
    color_oracle_name: string | null;
    po_type_oracle: string | null;
    state: LotState;
    status_oracle: string | null;
    greige_in_stock_oracle: number;
    issued: number;
    wip: number;
    finished: number;
};

// ---- Production sheet screen (ProductionSheetController::index, ::lotContext) ---------------------------------

/** One row of the sheet list (query mode results). */
export type SheetListRow = {
    id: number;
    sheet_date: string;
    shift: string;
    department: string;
    department_id: number;
    machine: string;
    lines_count: number;
    qty_out: number;
    entered_by: string | null;
    can_edit: boolean;
};

export type OutputType = 'dyed' | 'print';

/**
 * A production line as the grid holds it. Server fields plus what the screen keeps per row while editing: the
 * client row id the grid keys its nodes by (and the page finds the row by, since the grid holds copies), the lot
 * label, the stretch flag of the operation (for the footer), the metres available at the source position, and
 * the override reasons a refused save collected before re-submitting.
 */
export type SheetLineRow = {
    _uid: string;
    id: number | null;
    batch_no: string | null;
    lot_id: number | null;
    lot: string;
    lot_route_step_id: number | null;
    operation_id: number | null;
    operation: string;
    is_stretch: boolean;
    is_reprocess: boolean;
    reprocess_reason: string | null;
    qty_in: number | null;
    qty_out: number | null;
    output_type: OutputType | null;
    /** `production_lines.g_width` — the width keyed on this line, defaulted from the lot's `gw_oracle`. */
    g_width: number | null;
    f_width: number | null;
    remarks: string | null;
    colour_override: string | null;
    available: number | null;
    override_reason: string | null;
    colour_override_reason: string | null;
};

/** What a save sends per line (ProductionSheetService's LinePayload). */
export type SheetLinePayload = {
    id: number | null;
    batch_no: string | null;
    lot_id: number;
    lot_route_step_id: number | null;
    operation_id: number;
    is_reprocess: boolean;
    reprocess_reason: string | null;
    qty_in: number | null;
    qty_out: number | null;
    output_type: OutputType | null;
    g_width: number | null;
    f_width: number | null;
    remarks: string | null;
    override_reason: string | null;
    colour_override_reason: string | null;
};

export type SheetDetail = {
    id: number;
    sheet_date: string;
    shift_id: number;
    department_id: number;
    machine_id: number;
    downtime_minutes: number;
    remarks: string | null;
    can_edit: boolean;
    lines: Omit<
        SheetLineRow,
        '_uid' | 'available' | 'override_reason' | 'colour_override_reason'
    >[];
    totals: import('@/lib/sheetTotals').SheetTotals;
};

export type LotContextStep = {
    id: number;
    seq: number;
    operation_id: number;
    operation: string;
    department_id: number;
    department: string;
    status: RouteStepStatus;
    is_added: boolean;
    from_position: string | null;
    to_position: string | null;
    /** Metres waiting at the step's source position. */
    available: number;
};

/** GET production/sheets/lot-context/{lot}?department_id= */
export type LotContext = {
    lot_id: number;
    lot_no_oracle: string;
    po_no: number;
    party: string;
    quality: string | null;
    design: string | null;
    color_oracle_name: string | null;
    po_type_oracle: string | null;
    state: LotState;
    status_oracle: string | null;
    clr_status_oracle: string | null;
    colour_approved: boolean;
    balances: Record<string, number>;
    gw_oracle: number | null;
    f_width: number | null;
    /** The next pending step of the sheet's department; null: the line would be recorded as unplanned. */
    default_step_id: number | null;
    next_step_id: number | null;
    floor_position: string | null;
    floor_available: number;
    steps: LotContextStep[];
};

/** A select option the sheet screen receives (shifts, departments, machines, reprocess reasons). */
export type Option = {
    value: number | string;
    label: string;
    code?: string;
    department_ids?: number[];
};

/** An active operation as the sheet screen receives it: what a picked operation implies for a line. */
export type OperationOption = {
    value: number;
    label: string;
    department_id: number;
    kind: string;
    is_stretch: boolean;
    finish_type: OutputType | null;
};

// ---- Lots screen (LotController::index) -----------------------------------------------------------------------

/** An active `clr_status_oracle` lookup value; `approved` drives the ✓ marker (spec §5.4). */
export type ColourStatusOption = {
    value: string;
    label: string;
    approved: boolean;
};

/** An active `hold_reason` lookup value, for the hold / cancel ReasonDialog. */
export type HoldReasonOption = { value: string; label: string };

/** The report's Pending / Complete flag (spec §5.3 "Process flag"). */
export type ProcessFlag = 'pending' | 'complete';

/**
 * One lot as the Lots screen receives it. Everything but `clr_status_oracle` and `remarks` is read-only here:
 * `Lot::ORACLE_FIELDS` belong to the import and the quantities plus `status_oracle` to the ledger. The Oracle
 * identifiers (`oracle_line_id`, `design_oracle_id`, `color_oracle_id`) are not sent: the Lot Inquiry shows those.
 */
export type LotScreenRow = {
    id: number;
    lot_no_oracle: string;
    contract_id: number;
    po_no: number;
    party: string;
    ref_no: string | null;
    po_date: string;
    quality: string;
    gsm: number | null;
    design: string | null;
    color_oracle_name: string | null;
    clr_status_oracle: string | null;
    gw_oracle: number | null;
    f_width: number | null;
    po_type_oracle: string;
    state: LotState;
    hold_reason: string | null;
    cancel_reason: string | null;
    state_changed_at: string | null;
    status_oracle: string | null;
    process_flag: ProcessFlag;
    /** The reporting stage, derived from the status — or pinned by hand (`stage_overridden`). */
    stage_id: number | null;
    stage: string | null;
    stage_overridden: boolean;
    stage_overridden_at: string | null;
    stage_overridden_by: string | null;
    greige_received_oracle: number;
    greige_in_stock_oracle: number;
    qty_issued: number;
    wip: number;
    finished: number;
    dispatch: number;
    loss_qty: number;
    last_movement_at: string | null;
    oracle_missing_at: string | null;
    remarks: string | null;
};

/**
 * The flat, Oracle-shaped lot payload — `ProductionAnalyticsService::flatLots()`, key for key and in the same
 * order (`FLAT_KEYS`). These are the mill's own field names, and since the physical rename they are the
 * schema's too: `lots` carries them under exactly these names, with the party, the PO and the quality
 * denormalised onto the row.
 *
 * Metres are numbers, not the driver's decimal strings. A null stays null: an unset greige width or an
 * unresolved stage is absent, never `0` or `''`. `date_oracle` is already dd-mm-yyyy.
 *
 * The last four keys are additive: `stage_name`, the two vestigial `stage_process_*` keys (always null —
 * resolution is stage-only now, and they survive only so the 25-key shape does), and `lot_id`, the real
 * primary key the grid links to the Lot Inquiry with.
 */
export type FlatLotRow = {
    party_oracle_id: number | null;
    party_oracle_name: string;
    po_no: number;
    ref: string | null;
    design_oracle_id: string | null;
    color_oracle_id: string | null;
    color_oracle_name: string | null;
    clr_status_oracle: string | null;
    po_type_oracle: string;
    date_oracle: string | null;
    lot_no_oracle: string;
    quality: string;
    gw_oracle: number | null;
    status_oracle: string | null;
    greige_received_oracle: number;
    greige_in_stock_oracle: number;
    qty_issued: number;
    wip: number;
    finished: number;
    dispatch: number;
    stage_id: number | null;
    stage_name: string | null;
    /** Always null: `lots.stage_process_id` is gone. Kept so the payload's 25-key shape is unchanged. */
    stage_process_id: null;
    /** Always null, for the same reason as `stage_process_id`. */
    stage_process_name: null;
    lot_id: number;
};

/**
 * A flat row as the grid holds it. `DataGrid` keys its nodes by `id`, and the payload has no such key by
 * design (it is exactly the 25 fields above), so the page adds `id = lot_id` on the way in. Client-side only:
 * nothing is sent back from this grid.
 */
export type FlatLotGridRow = FlatLotRow & { id: number };

// ---- R1, the Month Wise Fabric Report (FabricReportController::screen) ----------------------------------------

/**
 * The report's six quantities and its lot count, at either totals level (stage, grand). One spelling does
 * for the flat row and the total now that the columns carry the mill's names; `@/lib/flatLotColumns`'
 * `FLAT_LOT_TOTAL_FIELDS` is the one place the six are declared.
 */
export type FabricReportTotals = {
    lots: number;
    greige_received_oracle: number;
    greige_in_stock_oracle: number;
    qty_issued: number;
    wip: number;
    finished: number;
    dispatch: number;
};

/**
 * One stage of the report: its subtotal and the lots it claims, in the flat Oracle shape.
 *
 * `rows` can be **shorter than `lots`**, or empty while `lots` is not: the totals are summed over every
 * match in the database while the rows are capped across the whole report, so the page must say when the
 * report was truncated (`rowsTotal > rowCap`). `id` is null for the `unclassified` row, which holds the
 * lots no stage claims.
 */
export type FabricReportStage = FabricReportTotals & {
    id: number | null;
    code: string;
    name: string;
    rows: FlatLotRow[];
};

/** R1's Process parameter (spec §7.1): the pending work, the finished work, or both. */
export type FabricReportProcessFilter = 'pending' | 'complete' | 'all';

/** The two parameters the report carries as discrete choices rather than as query-mode criteria. */
export type FabricReportParams = {
    /** `YYYY-MM` of the PO date, or null for every month ("All"). */
    month: string | null;
    process: FabricReportProcessFilter;
};

/** One entry of the Month parameter: `YYYY-MM` and the mill's `Mmm-yy` label. */
export type FabricReportMonth = { value: string; label: string };

/** The Oracle Reports header block's server-side facts (spec §4.3); title and parameters come from `t()`. */
export type FabricReportHeader = {
    company: string;
    /** dd-mm-yyyy HH:mm on the mill's clock. */
    run_at: string;
    user: string;
};

/** Which columns the Lots grid shows: the app's own `LotScreenRow`, or the flat Oracle-shaped payload. */
export type LotGridShape = 'nested' | 'flat';

/** What `PUT production/lots/{lot}` accepts — the two app-owned fields, each behind its own permission. */
export type LotScreenEdit = {
    clr_status_oracle: string | null;
    remarks: string | null;
};

/** Who may do what on the Lots screen (LotController::index). */
export type LotScreenPermissions = {
    colour: boolean;
    remarks: boolean;
    hold: boolean;
    route: boolean;
    /** May pin a lot's reporting stage by hand, and clear the pin (`production.route.manage`). */
    stage: boolean;
};

/** What `PUT production/lots/{lot}/stage` accepts: the stage to pin, required. */
export type LotStageOverride = {
    stage_id: number | null;
};

/** One unmapped status as the Unmapped Statuses screen receives it (UnmappedStatusController::index). */
export type UnmappedStatusRow = {
    /** The grid's row id: the lowest lot id carrying the status (the lot the row opens). */
    id: number;
    label: string;
    normalised: string | null;
    lots: number;
    lot_id: number;
};

// ---- POs / Contracts screen (ContractController::index) --------------------------------------------------------

/** One PO as the Contracts screen receives it: the Oracle header plus its lot totals, summed in the database. */
export type ContractScreenRow = {
    id: number;
    po_no: number;
    party: string;
    ref_no: string | null;
    po_date: string;
    contract_qty: number;
    lots_count: number;
    oracle_missing_at: string | null;
    greige_received_oracle: number;
    greige_in_stock_oracle: number;
    qty_issued: number;
    wip: number;
    finished: number;
    dispatch: number;
    loss_qty: number;
};
