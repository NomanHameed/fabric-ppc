<?php

return [
    'titles' => [
        'departments' => 'شعبے', 'machines' => 'مشینیں', 'operations' => 'آپریشنز', 'shifts' => 'شفٹیں',
        'qualities' => 'کوالٹیز', 'lookups' => 'فہرستیں', 'parties' => 'پارٹیاں', 'route_templates' => 'روٹ ٹیمپلیٹس',
    ],
    'fields' => [
        'code' => 'کوڈ', 'name' => 'نام', 'name_en' => 'نام (انگریزی)', 'name_ur' => 'نام (اردو)', 'sort' => 'ترتیب',
        'is_active' => 'فعال', 'department' => 'شعبہ', 'departments' => 'شعبے', 'kind' => 'قسم', 'stage_label' => 'اسٹیٹس لیبل',
        'counts_as_finish_production' => 'فنش پروڈکشن میں شامل', 'finish_type' => 'فنش کی قسم', 'is_stretch' => 'اسٹریچ',
        'starts_at' => 'آغاز', 'ends_at' => 'اختتام', 'oracle_code' => 'اوریکل کوڈ', 'description' => 'تفصیل',
        'ends' => 'اینڈز', 'picks' => 'پکس', 'warp_count' => 'وارپ کاؤنٹ', 'weft_count' => 'ویفٹ کاؤنٹ',
        'composition' => 'ساخت', 'loom' => 'لوم', 'notes' => 'نوٹس', 'list_key' => 'فہرست', 'label' => 'لیبل',
        'label_en' => 'لیبل (انگریزی)', 'label_ur' => 'لیبل (اردو)', 'approved' => 'منظور شدہ شمار', 'type' => 'قسم',
        'email' => 'ای میل', 'alias' => 'متبادل نام', 'aliases' => 'متبادل نام', 'po_type' => 'پی او قسم',
        'quality_pattern' => 'کوالٹی پیٹرن', 'priority' => 'ترجیح', 'seq' => 'نمبر', 'operation' => 'آپریشن', 'steps' => 'مراحل',
    ],
    'options' => [
        'normal' => 'عام', 'reprocess' => 'دوبارہ پراسیس', 'dyed' => 'ڈائیڈ', 'print' => 'پرنٹ', 'brand' => 'برانڈ', 'local' => 'لوکل',
        'colour_status' => 'رنگ کی حالت', 'po_type' => 'پی او قسم', 'loss_reason' => 'نقصان کی وجہ',
        'hold_reason' => 'روکنے کی وجہ', 'reprocess_reason' => 'دوبارہ پراسیس کی وجہ',
    ],
    'quality_pattern_hint' => 'SQL LIKE پیٹرن، مثلاً %VISCOSE%',
];
