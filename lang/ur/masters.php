<?php

return [
    'titles' => [
        'departments' => 'شعبے', 'machines' => 'مشینیں', 'operations' => 'آپریشنز', 'shifts' => 'شفٹیں',
        'qualities' => 'کوالٹیز', 'lookups' => 'فہرستیں', 'parties' => 'پارٹیاں', 'route_templates' => 'روٹ ٹیمپلیٹس',
        'stages' => 'مراحل', 'stage_processes' => 'مرحلے کے پراسیس',
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
        'hourly_capacity' => 'گنجائش / گھنٹہ', 'daily_capacity' => 'گنجائش / دن', 'capacity_uom' => 'گنجائش کی اکائی',
        'stage' => 'مرحلہ', 'sort_order' => 'ترتیب', 'quantity_type' => 'مقدار',
        'is_wip' => 'زیرِ عمل', 'is_finished' => 'مکمل', 'is_dispatch' => 'روانہ',
        'status_aliases' => 'وہ اسٹیٹس جو اس پراسیس کو ظاہر کرتے ہیں (کوما سے الگ؛ * وائلڈ کارڈ ہے، مثلاً DISP *)',
    ],
    'options' => [
        'normal' => 'عام', 'reprocess' => 'دوبارہ پراسیس', 'dyed' => 'ڈائیڈ', 'print' => 'پرنٹ', 'brand' => 'برانڈ', 'local' => 'لوکل',
        'colour_status' => 'رنگ کی حالت', 'po_type' => 'پی او قسم', 'loss_reason' => 'نقصان کی وجہ',
        'hold_reason' => 'روکنے کی وجہ', 'reprocess_reason' => 'دوبارہ پراسیس کی وجہ',
        'quantity_stock' => 'گریج اسٹاک میں', 'quantity_wip' => 'زیرِ عمل', 'quantity_finished' => 'مکمل',
        'quantity_dispatched' => 'روانہ', 'quantity_loss' => 'نقصان',
    ],
    'quality_pattern_hint' => 'SQL LIKE پیٹرن، مثلاً %VISCOSE%',
];
