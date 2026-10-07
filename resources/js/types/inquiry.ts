import type { LotState, RouteStepStatus } from '@/types/production';

export type ProcessFlag = 'pending' | 'complete';

export type InquiryTab = 'route' | 'movements' | 'lines' | 'audit';

/** The seven derived quantities (spec §5.3 "Derived lot values"), on the lot summary and on every PO row. */
export type LotQuantities = {
    greige_qty: number;
    stock_qty: number;
    issued_qty: number;
    wip_qty: number;
    finished_qty: number;
    dispatched_qty: number;
    loss_qty: number;
};

/** The lot summary of the Lot Inquiry (InquiryController::lot → `lot`). */
export type InquiryLot = LotQuantities & {
    id: number;
    lot_no: string;
    oracle_line_id: number;
    contract_id: number;
    po_no: number;
    party: string;
    ref_no: string | null;
    po_date: string;
    contract_qty: number;
    quality: string;
    gsm: number | null;
    design_code: string | null;
    design: string | null;
    colour_code: string | null;
    colour: string | null;
    colour_status: string | null;
    g_width: number | null;
    f_width: number | null;
    po_type: string | null;
    state: LotState;
    hold_reason: string | null;
    cancel_reason: string | null;
    state_changed_at: string | null;
    current_label: string | null;
    process_flag: ProcessFlag;
    /** The reporting stage, derived from the status unless `stage_overridden` says PPC pinned it. */
    stage_id: number | null;
    stage: string | null;
    stage_process_id: number | null;
    stage_process: string | null;
    stage_overridden: boolean;
    stage_overridden_at: string | null;
    stage_overridden_by: string | null;
    oracle_missing_at: string | null;
    last_movement_at: string | null;
    loss_reason: string | null;
    closed_at: string | null;
    remarks: string | null;
};

/** Record n of m among the lots of the same PO, for Previous / Next. */
export type LotSiblings = {
    index: number;
    count: number;
    prev_id: number | null;
    next_id: number | null;
};

/** Metres at one position; `kind` is the `production.positions.*` key, a step position names its step. */
export type PositionRow = {
    position: string;
    kind: 'stock' | 'issued' | 'step' | 'finished' | 'dispatched' | 'loss';
    qty: number;
    step_seq: number | null;
    operation: string | null;
};

/** One step of the route timeline. */
export type TimelineStep = {
    id: number;
    seq: number;
    operation: string;
    stage_label: string | null;
    department: string | null;
    status: RouteStepStatus;
    is_added: boolean;
    from_position: string | null;
    to_position: string | null;
    /** Metres waiting at the step's source position. */
    available: number;
    /** Metres that completed this step and wait after it. */
    at_position: number;
    last_done_at: string | null;
};

export type MovementRow = {
    id: number;
    moved_at: string;
    kind: string;
    from: string | null;
    to: string | null;
    qty_out: number;
    qty_in: number;
    user: string | null;
    reason: string | null;
    reversal_of_id: number | null;
    reversed_by_id: number | null;
    /** 'line' (a sheet line), 'import' (an Oracle import row), another source's class name, or null. */
    source_type: string | null;
    source_id: number | null;
    sheet_id: number | null;
    import_id: number | null;
};

export type LineRow = {
    id: number;
    sheet_id: number;
    date: string;
    shift: string;
    machine: string;
    operation: string;
    step_seq: number | null;
    batch_no: string | null;
    is_reprocess: boolean;
    reprocess_reason: string | null;
    qty_in: number;
    qty_out: number;
    output_type: string | null;
    g_width: number | null;
    f_width: number | null;
    colour_override: string | null;
    remarks: string | null;
};

export type AuditRow = {
    id: number;
    at: string | null;
    user: string | null;
    event: string;
    model: string;
    record_id: number;
    old: Record<string, unknown>;
    new: Record<string, unknown>;
};

/** A capped tab: the rows shown and how many there are in all ("showing n of m"). */
export type Capped<T> = { rows: T[]; total: number };

export type ReasonOption = {
    value: string;
    label: string;
    is_active?: boolean;
};

/** A row of the PO Inquiry's lots grid. */
export type PoLotRow = LotQuantities & {
    id: number;
    lot_no: string;
    quality: string;
    design: string | null;
    colour: string | null;
    colour_status: string | null;
    po_type: string | null;
    state: LotState;
    current_label: string | null;
    stage_id: number | null;
    stage: string | null;
    stage_process_id: number | null;
    stage_process: string | null;
    process_flag: ProcessFlag;
    oracle_missing_at: string | null;
};

export type InquiryContract = {
    id: number;
    po_no: number;
    party: string;
    ref_no: string | null;
    po_date: string;
    contract_qty: number;
    oracle_missing_at: string | null;
    lots_count: number;
};

/** A lot on the search chooser. */
export type SearchLotRow = {
    id: number;
    lot_no: string;
    po_no: number;
    party: string;
    design: string | null;
    colour: string | null;
    quality: string;
    state: LotState;
    current_label: string | null;
    stock_qty: number;
    wip_qty: number;
    finished_qty: number;
};

export type SearchContractRow = {
    id: number;
    po_no: number;
    party: string;
    ref_no: string | null;
    po_date: string;
};
