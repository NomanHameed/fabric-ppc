/** Shapes served by `GET /production/analytics` and `GET /production/utilisation`. */

export type ChartSeries = {
    id: number | null;
    key: string;
    name: string;
    total: number;
    data: number[];
};

export type WipStage = { key: string; name: string; qty: number };

export type UtilisationRow = {
    id: number;
    code: string;
    name: string;
    allocated_qty: number;
    /** Null when nobody has entered a capacity: utilisation is unknown, not zero and not full. */
    capacity_qty: number | null;
    free_qty: number | null;
    utilisation_pct: number | null;
    days: number;
    uom: string;
};

export type DepartmentOption = { id: number; code: string; name: string };
export type OperationOption = {
    id: number;
    name: string;
    department_id: number;
};

export type DashboardAnalytics = {
    range: { from: string; to: string; days: number };
    dates: string[];
    by_department: ChartSeries[];
    flow: ChartSeries[];
    wip_by_stage: WipStage[];
    kpi: {
        produced: number;
        issued: number;
        dispatched: number;
        loss: number;
        wip: number;
    };
    utilisation: {
        machines: UtilisationRow[];
        departments: UtilisationRow[];
    } | null;
    filters: {
        departments: DepartmentOption[];
        operations: OperationOption[];
        applied: { department_id: number | null; operation_id: number | null };
    };
};

export type UtilisationResponse = {
    range: { from: string; to: string };
    machines: UtilisationRow[];
    departments: UtilisationRow[];
    department: UtilisationRow | null;
};

export type TimelineEvent = {
    id: string;
    type: string;
    title: string;
    occurred_at: string;
    from: string | null;
    to: string | null;
    qty: number | null;
    actor_id: number | null;
    actor: string | null;
    note: string | null;
};

export type TimelineResponse = {
    lot: { id: number; lot_no: string; state: string };
    events: TimelineEvent[];
};
