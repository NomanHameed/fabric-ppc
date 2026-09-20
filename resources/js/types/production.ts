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
