<?php

return [
    'title' => 'User actions',
    'description' => 'Every meaningful action, with the person who took it, the record it touched, and the address and computer it came from.',
    'empty' => 'No actions match these filters.',
    'device' => [
        'title' => 'This computer',
        'name' => 'Computer name',
        'hint' => 'The name this terminal is known by on the floor. It appears next to every action recorded from it.',
        'unnamed' => 'Unnamed computer (…:id)',
        'saved' => 'Computer name saved.',
        'description' => 'Actions are recorded against the terminal they came from. Give this one the name the floor uses for it.',
        'save' => 'Save name',
        'id' => 'Device id',
        'last_seen' => 'Last seen',
        'prompt_title' => 'Name this computer',
        'prompt_body' => 'This terminal has no name yet. Naming it makes the action log readable — nobody has to work out which machine a row came from.',
        'dismiss' => 'Not now',
        'open' => 'Name it',
    ],
    'filters' => [
        'title' => 'Filters',
        'user' => 'User',
        'action' => 'Action',
        'device' => 'Computer',
        'from' => 'From',
        'to' => 'To',
        'all_users' => 'All users',
        'all_actions' => 'All actions',
        'all_devices' => 'All computers',
        'apply' => 'Apply',
        'reset' => 'Clear filters',
    ],
    'paging' => [
        'showing' => 'Showing :from–:to of :total',
        'page' => 'Page :page of :pages',
        'previous' => 'Previous',
        'next' => 'Next',
    ],
    'subject' => [
        'none' => '—',
    ],
    'columns' => [
        'when' => 'When', 'user' => 'User', 'action' => 'Action', 'subject' => 'Record',
        'computer' => 'Computer', 'ip' => 'IP address', 'what' => 'What happened',
    ],
    'action_names' => [
        'user' => [
            'registered' => 'Signed up',
            'approved' => 'Account approved',
            'rejected' => 'Account rejected',
        ],
        'device' => [
            'named' => 'Computer named',
        ],
        'lot' => [
            'issued' => 'Lot issued',
            'moved' => 'Lot moved',
            'held' => 'Lot held',
            'released' => 'Lot released',
            'cancelled' => 'Lot cancelled',
            'closed' => 'Lot closed',
            'dispatched' => 'Lot dispatched',
        ],
        'colour' => [
            'approved' => 'Colour approved',
            'rejected' => 'Colour rejected',
        ],
        'capacity' => [
            'allocated' => 'Capacity allocated',
        ],
    ],
    // One line per ActionKeys constant. ActionLogger renders these once, in the fallback locale, into
    // user_actions.description; UserAction::label() re-renders them for the reader.
    'actions' => [
        'user' => [
            'registered' => 'Signed up as :email',
            'approved' => 'Approved the account of :name',
            'rejected' => 'Rejected the account of :name (:reason)',
        ],
        'device' => [
            'named' => 'Registered this computer as :computer',
        ],
        'lot' => [
            'issued' => 'Issued :qty m of lot :lot to the floor',
            'moved' => 'Moved lot :lot from :from to :to',
            'held' => 'Put lot :lot on hold (:reason)',
            'released' => 'Released lot :lot from hold',
            'cancelled' => 'Cancelled lot :lot (:reason)',
            'closed' => 'Closed lot :lot',
            'dispatched' => 'Dispatched :qty m of lot :lot',
        ],
        'colour' => [
            'approved' => 'Approved the colour :colour on lot :lot',
            'rejected' => 'Rejected the colour :colour on lot :lot',
        ],
        'capacity' => [
            'allocated' => 'Allocated :qty m on :machine for :date',
        ],
    ],
];
