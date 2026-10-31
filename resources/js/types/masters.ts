export type FieldType =
    | 'text'
    | 'number'
    | 'select'
    | 'multiselect'
    | 'bool'
    | 'time'
    | 'textarea'
    | 'lov';

export type MasterField = {
    name: string;
    type: FieldType;
    label: string;
    required: boolean;
    options: { value: string | number | null; label: string }[];
    lov: string | null;
    displayFrom: string | null;
    span: number;
    grid: boolean;
    queryable: boolean;
    readonly: boolean;
};

export type MasterMeta = {
    key: string;
    title: string;
    canEdit: boolean;
    fields: MasterField[];
    /** The Oracle import owns these rows: the screen queries them, it never writes them. */
    oracleOwned: boolean;
    /** On an Oracle-owned master, the fields this application still owns (empty = none). */
    appOwnedFields: string[];
};

export type MasterRow = { id: number; [field: string]: unknown };
