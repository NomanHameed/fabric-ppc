<?php

return [
    'title' => 'Production',
    'positions' => [
        'stock' => 'Greige in stock', 'issued' => 'Issued to floor', 'step' => 'At route step', 'finished' => 'Finished',
        'dispatched' => 'Dispatched', 'loss' => 'Loss',
    ],
    'kinds' => [
        'import' => 'Greige received', 'issue' => 'Issue to floor', 'process' => 'Production', 'reprocess' => 'Reprocess',
        'dispatch' => 'Dispatch', 'loss' => 'Loss', 'adjustment' => 'Adjustment',
    ],
    'states' => ['open' => 'Open', 'hold' => 'On hold', 'closed' => 'Closed', 'cancelled' => 'Cancelled'],
    'errors' => [
        'insufficient_quantity' => 'Lot :lot has only :available m at :position; :requested m requested.',
        'lot_not_open' => 'Lot :lot is :state; no production can be recorded on it.',
        'invariant' => 'Ledger balances for lot :lot do not match its movements at: :positions.',
        'already_reversed' => 'Movement #:id has already been reversed.',
        'cannot_reverse_reversal' => 'Movement #:id is itself a reversal and cannot be reversed; post the original movement again instead.',
    ],
];
