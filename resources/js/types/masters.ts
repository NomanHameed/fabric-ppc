export type FieldType = 'text' | 'number' | 'select' | 'multiselect' | 'bool' | 'time' | 'textarea' | 'lov';

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

export type MasterMeta = { key: string; title: string; canEdit: boolean; fields: MasterField[] };

export type MasterRow = { id: number; [field: string]: unknown };
