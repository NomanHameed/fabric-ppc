<?php

return [
    'titles' => [
        'departments' => 'Departments', 'machines' => 'Machines', 'operations' => 'Operations', 'shifts' => 'Shifts',
        'qualities' => 'Qualities', 'lookups' => 'Lookup Lists', 'parties' => 'Parties', 'route_templates' => 'Route Templates',
        'stages' => 'Stages', 'stage_processes' => 'Stage Processes',
    ],
    'fields' => [
        'code' => 'Code', 'name' => 'Name', 'name_en' => 'Name (English)', 'name_ur' => 'Name (Urdu)', 'sort' => 'Sort',
        'is_active' => 'Active', 'department' => 'Department', 'departments' => 'Departments', 'kind' => 'Kind',
        'stage_label' => 'Status label', 'counts_as_finish_production' => 'Counts as finish production',
        'finish_type' => 'Finish type', 'is_stretch' => 'Stretch', 'starts_at' => 'Starts', 'ends_at' => 'Ends',
        'oracle_code' => 'Oracle code', 'description' => 'Description', 'ends' => 'Ends/inch', 'picks' => 'Picks/inch',
        'warp_count' => 'Warp count', 'weft_count' => 'Weft count', 'composition' => 'Composition', 'loom' => 'Loom',
        'notes' => 'Notes', 'list_key' => 'List', 'label' => 'Label', 'label_en' => 'Label (English)', 'label_ur' => 'Label (Urdu)',
        'approved' => 'Counts as approved', 'type' => 'Type', 'email' => 'Email', 'alias' => 'Alias', 'aliases' => 'Aliases',
        'po_type' => 'PO type', 'quality_pattern' => 'Quality pattern', 'priority' => 'Priority', 'seq' => 'Seq',
        'hourly_capacity' => 'Capacity / hour', 'daily_capacity' => 'Capacity / day', 'capacity_uom' => 'Capacity unit',
        'operation' => 'Operation', 'steps' => 'Steps',
        'stage' => 'Stage', 'sort_order' => 'Sort order',
        'status_aliases' => 'Statuses that mean this stage (comma separated; * is a wildcard, e.g. DISP *; #BLANK means no status at all)',
        // The Oracle production line a stage process row now holds. Same wording as the report's own column
        // headers (`production.flat.*`), restated here because a Foundation screen reads the masters group.
        'lot_no_oracle' => 'Lot # (Oracle)', 'po_no' => 'PO #', 'ref' => 'Ref.',
        'party_oracle_id' => 'Party code', 'party_oracle_name' => 'Party', 'date_oracle' => 'PO date',
        'design_oracle_id' => 'Design code', 'color_oracle_id' => 'Colour code', 'color_oracle_name' => 'Colour / Shade',
        'clr_status_oracle' => 'Colour status', 'po_type_oracle' => 'PO type', 'quality' => 'Quality',
        'gw_oracle' => 'G/W', 'status_oracle' => 'Status',
        'greige_received_oracle' => 'Greige received', 'greige_in_stock_oracle' => 'Greige in stock',
        'qty_issued' => 'Qty issued', 'wip' => 'WIP', 'finished' => 'Finished', 'dispatch' => 'Dispatch',
    ],
    'options' => [
        'normal' => 'Normal', 'reprocess' => 'Reprocess', 'dyed' => 'Dyed', 'print' => 'Print', 'brand' => 'Brand', 'local' => 'Local',
        'clr_status_oracle' => 'Colour status', 'po_type' => 'PO type', 'loss_reason' => 'Loss reason',
        'hold_reason' => 'Hold reason', 'reprocess_reason' => 'Reprocess reason',
    ],
    'quality_pattern_hint' => 'SQL LIKE pattern, e.g. %VISCOSE%',
    // Oracle-owned masters (App\Modules\Foundation\Masters\OracleOwned): the screen stays, the writing stops.
    'oracle_owned' => [
        'note' => 'These records come from Oracle: query only. Use F7 to enter a query and F8 to run it.',
        'note_partial' => 'These records come from Oracle: the greyed-out fields are read-only. Only the fields this system owns can be changed here.',
        'cannot_create' => 'New records come from the Oracle import, not from this screen.',
        'cannot_update' => 'Oracle owns these values. Change them in Oracle and run the import again.',
        'cannot_delete' => 'Oracle owns these records. They cannot be deleted here.',
    ],
];
