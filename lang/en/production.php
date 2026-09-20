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
    'fields' => [
        'lot_no' => 'Lot #', 'po_no' => 'PO #', 'party' => 'Party', 'quality' => 'Quality', 'design' => 'Design', 'colour' => 'Colour / Shade',
        'ref_no' => 'Ref.', 'qty' => 'Metres', 'date' => 'Date', 'user' => 'User', 'remarks' => 'Remarks', 'reversal' => 'Reversed',
    ],
    'issue' => [
        'title' => 'Issue to Floor', 'per_lot' => 'Issue a lot', 'per_po' => 'Issue a whole PO', 'issue_all' => 'Issue all stock of this PO',
        'recent' => 'Recent issues', 'lot_hint' => 'Full lot no., last 4 digits, or a PO no. (F9 for the list)',
        'po_hint' => 'PO no. (F9 for the list); every open lot with greige in stock is issued in full',
        'available_hint' => ':qty m in stock', 'available' => 'In stock (m)', 'open_lots' => 'Open lots', 'no_stock' => 'This lot has no greige in stock.',
        'viewing' => 'Viewing a recorded issue; New record (Ctrl+↓) to issue again',
        'confirm_all' => 'Issue the whole remaining greige of every open lot on PO :po? (:n lots, :qty m)',
        'issued' => 'Issued :qty m of lot :lot to the floor.',
        'issued_contract' => 'Issued :n lot(s) of PO :po to the floor.',
        'nothing_to_issue' => 'PO :po has no open lot with greige in stock.',
    ],
    'reasons' => ['greige_changed' => 'Oracle greige changed from :old to :new'],
    'state' => ['held' => 'Lot :lot is on hold.', 'released' => 'Lot :lot is open again.', 'cancelled' => 'Lot :lot is cancelled.'],
    'errors' => [
        'insufficient_quantity' => 'Lot :lot has only :available m at :position; :requested m requested.',
        'lot_not_open' => 'Lot :lot is :state; no production can be recorded on it.',
        'invariant' => 'Ledger balances for lot :lot do not match its movements at: :positions.',
        'already_reversed' => 'Movement #:id has already been reversed.',
        'cannot_reverse_reversal' => 'Movement #:id is itself a reversal and cannot be reversed; post the original movement again instead.',
        'route_in_progress' => 'This lot already has production on its route; edit the steps instead of re-assigning.',
        'route_step_in_progress' => 'A route step with production or metres waiting cannot be removed, moved, skipped or given another operation.',
        'route_step_order' => 'Steps that already have production must keep their order.',
        'route_step_position_locked' => 'Adding or skipping a step here would move metres already at a completed step; add a reprocess line instead.',
        'no_cli_user' => 'No user found to record as uploader. Create an admin user or pass --user=<id>.',
        'cancel_has_wip' => 'Lot :lot has metres in process or finished; close it with a loss instead of cancelling.',
        'invalid_state_change' => 'Lot :lot is :state; this change is not allowed.',
        'unknown_reason' => 'Unknown reason code :code.',
    ],
];
