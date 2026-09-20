<?php

return [
    'title' => 'پروڈکشن',
    'positions' => [
        'stock' => 'گریج اسٹاک میں', 'issued' => 'فلور کو جاری', 'step' => 'روٹ مرحلے پر', 'finished' => 'مکمل',
        'dispatched' => 'روانہ', 'loss' => 'نقصان',
    ],
    'kinds' => [
        'import' => 'گریج موصول', 'issue' => 'فلور کو اجرا', 'process' => 'پروڈکشن', 'reprocess' => 'ری پروسیس',
        'dispatch' => 'ڈسپیچ', 'loss' => 'نقصان', 'adjustment' => 'ایڈجسٹمنٹ',
    ],
    'states' => ['open' => 'کھلا', 'hold' => 'روکا ہوا', 'closed' => 'بند', 'cancelled' => 'منسوخ'],
    'errors' => [
        'insufficient_quantity' => 'لاٹ :lot کے پاس :position پر صرف :available میٹر ہیں؛ :requested میٹر درکار ہیں۔',
        'lot_not_open' => 'لاٹ :lot :state ہے؛ اس پر پروڈکشن درج نہیں کی جا سکتی۔',
        'invariant' => 'لاٹ :lot کے لیجر بیلنس اس کی موومنٹس سے مطابقت نہیں رکھتے: :positions۔',
    ],
];
