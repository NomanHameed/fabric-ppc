export type LotState = 'open' | 'hold' | 'closed' | 'cancelled';

/** A row of the `lots` LOV (LotLov::row): the columns shown plus what a picking screen fills from it. */
export type LotSummary = {
    id: number;
    code: string;
    label: string;
    po_no: number;
    party: string;
    design: string | null;
    colour: string | null;
    quality: string;
    g_width: number | null;
    f_width: number | null;
    stock_qty: number;
    wip_qty: number;
    finished_qty: number;
    state: LotState;
    current_label: string | null;
    contract_id: number;
};

/** A row of the `contracts` LOV (ContractLov::row): open lots with greige in stock, and the open lots' stock in metres. */
export type ContractSummary = {
    id: number;
    code: string;
    label: string;
    ref_no: string | null;
    party: string;
    open_lots: number;
    stock_qty: number;
};

/** One `issue` movement as the Issue to Floor screen lists it (IssueController::index). */
export type IssueRow = {
    id: number;
    lot_id: number;
    lot_no: string;
    po_no: number;
    party: string;
    design: string | null;
    colour: string | null;
    quality: string | null;
    stock_qty: number;
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
    lot_no: string;
    po_no: number;
    party: string;
    contract_id: number;
    quality: string | null;
    design: string | null;
    colour: string | null;
    po_type: string | null;
    state: LotState;
    current_label: string | null;
    stock_qty: number;
    issued: number;
    wip_qty: number;
    finished_qty: number;
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
    lot_no: string;
    po_no: number;
    party: string;
    quality: string | null;
    design: string | null;
    colour: string | null;
    po_type: string | null;
    state: LotState;
    current_label: string | null;
    colour_status: string | null;
    colour_approved: boolean;
    balances: Record<string, number>;
    g_width: number | null;
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
