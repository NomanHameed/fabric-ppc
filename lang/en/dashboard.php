<?php

return [
    'open_lots' => 'Open lots',
    'open_contracts' => 'Open POs',
    'open_greige' => 'Open greige',
    'parties' => 'Active parties',
    'missing_from_oracle' => 'Missing from Oracle',
    'last_import' => 'Last Oracle import',
    'no_import' => 'No Oracle import has been committed yet.',
    'go_to_import' => 'Go to Oracle Import',
    'metres' => ':qty m',

    'title' => 'Dashboard',
    'filters' => [
        'title' => 'Filters',
        'from' => 'From',
        'to' => 'To',
        'department' => 'Department',
        'process' => 'Process',
        'all_departments' => 'All departments',
        'all_processes' => 'All processes',
        'apply' => 'Apply',
        'reset' => 'Reset',
        'range' => ':from to :to',
        'presets' => [
            'week' => 'Last 7 days',
            'month' => 'This month',
            'quarter' => 'Last 90 days',
        ],
    ],
    'kpi' => [
        'produced' => 'Produced',
        'issued' => 'Issued to floor',
        'dispatched' => 'Dispatched',
        'loss' => 'Loss',
        'wip' => 'On the floor now',
    ],
    'flow' => [
        'produced' => 'Produced',
        'issued' => 'Issued to floor',
        'dispatched' => 'Dispatched',
        'loss' => 'Loss',
    ],
    'stages' => [
        'stock' => 'Greige in stock',
        'issued' => 'Issued, not started',
        'finished' => 'Finished, not dispatched',
    ],
    'charts' => [
        'by_department' => 'Production by department',
        'by_department_hint' => 'Metres finishing an operation each day, credited to the department that owns it.',
        'flow' => 'Daily flow',
        'flow_hint' => 'What entered the floor, what came off it and what was dispatched, day by day.',
        'wip' => 'Where the metres are now',
        'wip_hint' => 'A snapshot of open and held lots, not a range.',
        'utilisation' => 'Machine capacity vs allocation',
        'utilisation_hint' => 'Metres committed against the machine\'s capacity for the range.',
        'department_utilisation' => 'Department capacity vs allocation',
        'empty' => 'Nothing was recorded in this range.',
        'loading' => 'Loading…',
        'failed' => 'The dashboard figures could not be loaded.',
        'retry' => 'Try again',
        'summary_view' => 'Shown as a summary on a narrow screen.',
        'no_capacity_charted' => 'Machines with no capacity on file are listed but not charted.',
        // Everything past the eighth band, summed: a chart with more colours than the palette holds would
        // repeat one, and two departments in the same colour is worse than one honest "other".
        'other' => 'Other',
    ],
    'axis' => [
        'metres' => 'Metres',
        'date' => 'Date',
        'percent' => 'Utilisation %',
    ],
    'totals' => [
        'range_total' => 'Total for the range',
        'share' => ':pct% of the range',
    ],
    'links' => [
        'capacity' => 'Open machine capacity',
        'timeline' => 'Open a lot timeline',
        'lots' => 'Open lots',
    ],
];
