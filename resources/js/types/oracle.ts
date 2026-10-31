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

/*
 * The read-only Oracle source schema browser (oracle/SourceBrowser). Everything below describes the
 * *dictionary*, never business data: object names, column names and types, and the optimizer's row
 * estimate — nothing that holds a lot, a party or a quantity.
 */

export type SourceObjectType = 'TABLE' | 'VIEW';

/** The `oracle_src` connection as configured. There is no password field here, by design. */
export type SourceConnection = {
    driver: string;
    /** True for a local MySQL/SQLite stand-in; false when this is a real Oracle instance. */
    mocked: boolean;
    username: string;
    host: string;
    port: string;
    service: string;
    schema_prefix: string;
    server_version: string;
};

export type SourceObject = {
    owner: string;
    name: string;
    type: SourceObjectType;
    /** Null when the dictionary would not say (never a guess). */
    columns: number | null;
    /** ALL_TABLES.NUM_ROWS — an optimizer estimate, never a count(*). Null when there are no statistics. */
    rows: number | null;
    /** When those statistics were gathered, so a stale number can be seen to be stale. */
    analysed_at: string | null;
};

/** As the grid needs it: `owner.name` is the row identity. */
export type SourceObjectRow = SourceObject & { id: string };

export type SourceColumn = {
    position: number;
    name: string;
    /** Rendered the way Oracle writes it: VARCHAR2(40), NUMBER(12,2), DATE. */
    type: string;
    nullable: boolean;
    default: string | null;
};

export type SourceColumnRow = SourceColumn & { id: number };

export type SourceObjectPage = {
    rows: SourceObject[];
    total: number;
    page: number;
    per_page: number;
    pages: number;
};

export type SourceOwner = { owner: string; objects: number };

export type SourceSelection = {
    owner: string;
    name: string;
    type: SourceObjectType;
    columns: SourceColumn[];
};

/** A failure the screen renders as a message instead of an exception page. */
export type SourceError = {
    message: string;
    /** The ORA- line itself, when Oracle gave one. */
    detail: string | null;
    code: string | null;
    hints: string[];
};

export type SourceFilters = {
    q: string | null;
    owner: string | null;
    type: SourceObjectType | null;
    page: number;
};
