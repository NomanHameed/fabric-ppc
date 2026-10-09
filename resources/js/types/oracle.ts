export type ImportAction =
    | 'new'
    | 'changed'
    | 'unchanged'
    | 'missing'
    | 'error';
export type ImportStatus =
    | 'previewed'
    | 'committing'
    | 'committed'
    | 'failed'
    | 'discarded';

export type ImportSummary = {
    id: number;
    label: string;
    source: 'excel' | 'api';
    status: ImportStatus;
    uploader: string | null;
    created_at: string | null;
    committed_at: string | null;
    new_count: number;
    changed_count: number;
    unchanged_count: number;
    missing_count: number;
    error_count: number;
};

export type ImportDetail = ImportSummary & {
    unresolved_parties: { code: number; name: string; rows: number }[];
    error_message: string | null;
};

export type ImportRow = {
    id: number;
    row_no: number | null;
    oracle_line_id: number | null;
    action: ImportAction;
    message: string | null;
    diff: Record<string, [unknown, unknown]> | null;
    lot_no_oracle: string | null;
    po_no: number | null;
    party: string | null;
    design: string | null;
    color_oracle_name: string | null;
    quality: string | null;
    greige_received_oracle: number | null;
};
