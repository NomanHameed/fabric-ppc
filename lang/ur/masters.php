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
        'stage' => 'مرحلہ', 'sort_order' => 'ترتیب',
        'status_aliases' => 'وہ اسٹیٹس جو اس مرحلے کو ظاہر کرتے ہیں (کوما سے الگ؛ * وائلڈ کارڈ ہے، مثلاً DISP *؛ #BLANK کا مطلب کوئی اسٹیٹس نہیں)',
        // مرحلے کے پراسیس کی اوریکل پروڈکشن لائن — الفاظ رپورٹ کے کالم ہیڈرز (`production.flat.*`) والے ہی ہیں۔
        'lot_no_oracle' => 'لاٹ نمبر (اوریکل)', 'po_no' => 'پی او نمبر', 'ref' => 'حوالہ',
        'party_oracle_id' => 'پارٹی کوڈ', 'party_oracle_name' => 'پارٹی', 'date_oracle' => 'پی او کی تاریخ',
        'design_oracle_id' => 'ڈیزائن کوڈ', 'color_oracle_id' => 'رنگ کوڈ', 'color_oracle_name' => 'رنگ / شیڈ',
        'clr_status_oracle' => 'رنگ کی حیثیت', 'po_type_oracle' => 'پی او کی قسم', 'quality' => 'کوالٹی',
        'gw_oracle' => 'گریج چوڑائی', 'status_oracle' => 'حیثیت',
        'greige_received_oracle' => 'گریج موصول', 'greige_in_stock_oracle' => 'گریج اسٹاک میں',
        'qty_issued' => 'جاری شدہ مقدار', 'wip' => 'زیرِ عمل', 'finished' => 'مکمل', 'dispatch' => 'روانہ',
    ],
    'options' => [
        'normal' => 'عام', 'reprocess' => 'دوبارہ پراسیس', 'dyed' => 'ڈائیڈ', 'print' => 'پرنٹ', 'brand' => 'برانڈ', 'local' => 'لوکل',
        'clr_status_oracle' => 'رنگ کی حالت', 'po_type' => 'پی او قسم', 'loss_reason' => 'نقصان کی وجہ',
        'hold_reason' => 'روکنے کی وجہ', 'reprocess_reason' => 'دوبارہ پراسیس کی وجہ',
    ],
    'quality_pattern_hint' => 'SQL LIKE پیٹرن، مثلاً %VISCOSE%',
    // اوریکل کی ملکیت والے ماسٹرز (App\Modules\Foundation\Masters\OracleOwned): اسکرین موجود رہے گی، اندراج بند ہے۔
    'oracle_owned' => [
        'note' => 'یہ ریکارڈ اوریکل سے آتے ہیں: صرف دیکھنے اور تلاش کے لیے۔ تلاش کے لیے F7 اور چلانے کے لیے F8 دبائیں۔',
        'note_partial' => 'یہ ریکارڈ اوریکل سے آتے ہیں: غیر فعال خانے تبدیل نہیں ہو سکتے۔ صرف وہ خانے بدلے جا سکتے ہیں جو اس سسٹم کی ملکیت ہیں۔',
        'cannot_create' => 'نئے ریکارڈ اوریکل امپورٹ سے آتے ہیں، اس اسکرین سے نہیں۔',
        'cannot_update' => 'یہ قدریں اوریکل کی ملکیت ہیں۔ انہیں اوریکل میں تبدیل کریں اور امپورٹ دوبارہ چلائیں۔',
        'cannot_delete' => 'یہ ریکارڈ اوریکل کی ملکیت ہیں۔ انہیں یہاں سے حذف نہیں کیا جا سکتا۔',
    ],
];
