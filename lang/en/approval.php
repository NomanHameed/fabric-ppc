<?php

return [
    'blocked' => 'Your account is waiting for approval.',
    'pending' => [
        'title' => 'Waiting for approval',
        'description' => 'Your account has been created and your email address is on file. A moderator has to approve it before you can use the system.',
        'verify_first' => 'Please confirm your email address first — we have sent you a link.',
        'logout' => 'Log out',
    ],
    'verify' => [
        'title' => 'Confirm your email address',
        'description' => 'We sent a link to the address below. Open it to confirm the address, then a moderator can approve your account.',
    ],
    'rejected' => [
        'title' => 'Account not approved',
        'description' => 'A moderator did not approve this account.',
        'reason' => 'Reason',
        'contact' => 'If you think this is a mistake, speak to the PPC office.',
    ],
    'statuses' => [
        'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected',
    ],
    'fields' => [
        'status' => 'Approval', 'approved_by' => 'Approved by', 'approved_at' => 'Approved on',
        'rejected_reason' => 'Rejection reason', 'registered_at' => 'Signed up',
        'name' => 'Name', 'email' => 'Email address', 'email_verified' => 'Email confirmed',
        'device' => 'Computer', 'ip' => 'IP address',
    ],
    'verified' => 'Confirmed',
    'unverified' => 'Not confirmed',
    'unknown' => 'Not recorded',
    'queue' => [
        'title' => 'Signup approvals',
        'description' => 'Accounts waiting for a decision. Approving one lets it in straight away; rejecting one keeps it locked out and records why.',
        'empty' => 'No accounts are waiting for approval.',
        'waiting' => ':count waiting',
        'link' => 'Approval queue',
        'users_link' => 'All users',
    ],
    'confirm_approve' => 'Approve :name? They will be able to sign in straight away.',
    'reject_dialog' => [
        'title' => 'Reject :name',
        'message' => 'The reason is stored with the account and shown to the person who signed up.',
        'label' => 'Reason for rejection',
        'placeholder' => 'e.g. Not a mill employee',
    ],
    'actions' => [
        'approve' => 'Approve', 'reject' => 'Reject',
    ],
    'approved' => 'Account approved.',
    'rejected_done' => 'Account rejected.',
    'errors' => [
        'already_approved' => 'This account is already approved.',
        'already_decided' => 'A decision has already been recorded for this account.',
        'cannot_moderate_self' => 'You cannot approve or reject your own account.',
    ],
];
