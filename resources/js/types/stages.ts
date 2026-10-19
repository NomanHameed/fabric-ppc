/**
 * The stage / stage process read API (`GET /api/stages`, `/api/stages/{id}/processes`,
 * `/api/stage-processes/{id}`), as StageController returns it. Nothing in Vue hard-codes a stage:
 * StageSelect — the one select in use — reads these shapes from the API.
 */
export type Stage = {
    id: number;
    code: string;
    name: string;
    description: string | null;
    sort_order: number;
    /** The raw statuses that resolve to this stage; `*` is a wildcard and `#BLANK` claims a lot with no status. */
    status_aliases: string[];
    is_active: boolean;
    /** The production lines filed under the stage; only the list endpoint counts them, null otherwise. */
    processes_count: number | null;
};

/**
 * One Oracle production line filed under a stage, as `StageController::process()` returns it.
 *
 * **Deliberately restructured by the user** (migration `2026_10_13_000100`): a stage process is no longer a
 * code and a name in a process taxonomy but the mill's own Oracle row — party, PO, design, colour, lot
 * number, status and the six quantities, the same twenty fields `lots` carries. The quantities come back as
 * JSON numbers, and a column nobody filled in stays null.
 */
export type StageProcess = {
    id: number;
    stage_id: number;
    party_oracle_id: number | null;
    party_oracle_name: string | null;
    po_no: number | null;
    ref: string | null;
    design_oracle_id: string | null;
    color_oracle_id: string | null;
    color_oracle_name: string | null;
    clr_status_oracle: string | null;
    po_type_oracle: string | null;
    /**
     * `yyyy-mm-dd`; the API formats the PO date rather than sending a timestamp. ISO, not the `dd-mm-yyyy`
     * the master screen edits: a consumer of this API formats for display itself.
     */
    date_oracle: string | null;
    lot_no_oracle: string | null;
    quality: string | null;
    gw_oracle: number | null;
    status_oracle: string | null;
    greige_received_oracle: number | null;
    greige_in_stock_oracle: number | null;
    qty_issued: number | null;
    wip: number | null;
    finished: number | null;
    dispatch: number | null;
};

/** `GET /api/stages/{id}` — the stage with the production lines filed under it. */
export type StageWithProcesses = Stage & { processes: StageProcess[] };

export type StagesResponse = { data: Stage[] };
export type StageProcessesResponse = { data: StageProcess[] };

/**
 * A value the select must be able to show even when the API list does not contain it — the stage a lot
 * carries that has since been deactivated.
 */
export type StageFallback = { id: number; name: string } | null;
