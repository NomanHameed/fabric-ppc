/**
 * The stage / stage process read API (`GET /api/stages`, `/api/stages/{id}/processes`,
 * `/api/stage-processes/{id}`), as StageController returns it. Nothing in Vue hard-codes a stage:
 * StageSelect — the one select still in use — reads these shapes from the API.
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
    /** Only the list endpoint counts them; null when the caller did not ask. */
    processes_count: number | null;
};

/** Which of the report's quantities a process carries; null for a state that holds no metres. */
export type QuantityType = 'stock' | 'wip' | 'finished' | 'dispatched' | 'loss';

export type StageProcess = {
    id: number;
    stage_id: number;
    code: string;
    name: string;
    description: string | null;
    sort_order: number;
    quantity_type: QuantityType | null;
    is_wip: boolean;
    is_finished: boolean;
    is_dispatch: boolean;
    /**
     * The column survives on the table, but nothing reads it any more: resolution is stage-only, so a
     * status is claimed by `Stage.status_aliases` and never by a process.
     */
    status_aliases: string[];
    is_active: boolean;
};

/** `GET /api/stages/{id}` — the stage with its active processes. */
export type StageWithProcesses = Stage & { processes: StageProcess[] };

export type StagesResponse = { data: Stage[] };
export type StageProcessesResponse = { data: StageProcess[] };

/**
 * A value the select must be able to show even when the API list does not contain it — the stage a lot
 * carries that has since been deactivated.
 */
export type StageFallback = { id: number; name: string } | null;
