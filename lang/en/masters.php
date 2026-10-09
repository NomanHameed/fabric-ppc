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
        'stage' => 'Stage', 'sort_order' => 'Sort order', 'quantity_type' => 'Quantity',
        'is_wip' => 'WIP', 'is_finished' => 'Finished', 'is_dispatch' => 'Dispatch',
        'status_aliases' => 'Statuses that mean this process (comma separated; * is a wildcard, e.g. DISP *)',
    ],
    'options' => [
        'normal' => 'Normal', 'reprocess' => 'Reprocess', 'dyed' => 'Dyed', 'print' => 'Print', 'brand' => 'Brand', 'local' => 'Local',
        'clr_status_oracle' => 'Colour status', 'po_type' => 'PO type', 'loss_reason' => 'Loss reason',
        'hold_reason' => 'Hold reason', 'reprocess_reason' => 'Reprocess reason',
        'quantity_stock' => 'Greige in stock', 'quantity_wip' => 'WIP', 'quantity_finished' => 'Finished',
        'quantity_dispatched' => 'Dispatched', 'quantity_loss' => 'Loss',
    ],
    'quality_pattern_hint' => 'SQL LIKE pattern, e.g. %VISCOSE%',
];
