<?php

namespace Database\Seeders;

use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\LookupValue;
use App\Modules\Foundation\Models\Machine;
use App\Modules\Foundation\Models\Operation;
use App\Modules\Foundation\Models\Setting;
use App\Modules\Foundation\Models\Shift;
use Illuminate\Database\Seeder;

class FoundationSeeder extends Seeder
{
    /** Source name in operations.json => [code, name_en, name_ur, sort] */
    private const DEPARTMENTS = [
        'SINGEING' => ['SINGEING', 'Singeing', 'سنگنگ', 10],
        'KIER BLEACHING' => ['KIER_BLEACHING', 'Kier Bleaching', 'کیئر بلیچنگ', 20],
        'JIGGER DYEING' => ['JIGGER_DYEING', 'Jigger Dyeing', 'جگر ڈائنگ', 30],
        'MERCERIZING' => ['MERCERIZING', 'Mercerizing', 'مرسرائزنگ', 40],
        'BATCHING - STENTER' => ['BATCH_STENTER', 'Batching - Stenter', 'بیچنگ اسٹینٹر', 50],
        'PRINTING' => ['PRINTING', 'Printing', 'پرنٹنگ', 60],
        'CURING' => ['CURING', 'Curing', 'کیورنگ', 70],
        'DRYING' => ['DRYING', 'Drying', 'ڈرائنگ', 80],
        'FINISH - STENTER' => ['FINISH_STENTER', 'Finish - Stenter', 'فنش اسٹینٹر', 90],
        'SANFORIZING / CALANDER' => ['SANFO_CALENDER', 'Sanforizing / Calender', 'سینفورائزنگ / کیلنڈر', 100],
        'ROLLING' => ['ROLLING', 'Rolling', 'رولنگ', 110],
        'STITCHING' => ['STITCHING', 'Stitching', 'سلائی', 120],
    ];

    /** code => [name, department codes] */
    private const MACHINES = [
        'STENTER-1' => ['Stenter No. 1', ['FINISH_STENTER', 'BATCH_STENTER']],
        'STENTER-2' => ['Stenter No. 2', ['FINISH_STENTER', 'BATCH_STENTER']],
        'SANFO' => ['Sanforizer', ['SANFO_CALENDER']],
        'DRYING-1' => ['Drying #01', ['DRYING']],
        'DRYING-2' => ['Drying #02', ['DRYING']],
        'JUMBO' => ['Jumbo', ['DRYING']],
        'SINGEING' => ['Singeing', ['SINGEING']],
        'MERCERIZER' => ['Mercerizer', ['MERCERIZING']],
        'DIGITAL-PRINTER' => ['Digital Printer', ['PRINTING']],
        'JIGGER' => ['Jigger Dyeing', ['JIGGER_DYEING', 'KIER_BLEACHING']],
    ];

    /** list_key => [code, label_en, approved?] */
    private const LOOKUPS = [
        'colour_status' => [
            ['APPROVED', 'Approved', true], ['MWS_APP', 'MWS/APP.', true], ['DIRECT_PROD', 'DIRECT PROD', true],
            ['DIP', 'DIP', false], ['SHADE', 'SHADE', false], ['REVIEW', 'REVIEW', false],
            ['PRINT_MATCHING', 'Print Matching', false], ['RE_DO', 'RE-DO', false], ['REJECTED', 'REJECTED', false],
            ['SAMPLE', 'SAMPLE', false], ['DOX', 'DOX (to be defined)', false], ['REQ_DOX', 'REQ-DOX', false],
            ['WITHOUT_INSP', 'WITHOUT INSP', false], ['LCR', 'LCR (to be defined)', false],
        ],
        'po_type' => [
            ['DYED', 'DYED', false], ['PRINTED', 'PRINTED', false], ['HALF BLEACH', 'HALF BLEACH', false],
            ['PFD', 'PFD', false], ['PFGD', 'PFGD', false], ['DESIZE \\ WASH', 'DESIZE \\ WASH', false],
        ],
        'loss_reason' => [
            ['SHRINKAGE', 'Shrinkage', false], ['CUT_PIECES', 'Cut pieces', false], ['REJECTED', 'Rejected', false],
            ['RETURNED', 'Returned to party', false], ['OTHER', 'Other', false],
        ],
        'hold_reason' => [
            ['PARTY_HOLD', 'Party hold', false], ['QUALITY_ISSUE', 'Quality issue', false],
            ['AWAITING_APPROVAL', 'Awaiting approval', false], ['OTHER', 'Other', false],
        ],
        'reprocess_reason' => [
            ['SHADE', 'Shade', false], ['WIDTH', 'Width variation', false], ['BOWING_SKEW', 'Bowing / skew', false],
            ['WHITENESS', 'Whiteness', false], ['OTHER', 'Other', false],
        ],
    ];

    public function run(): void
    {
        $deptIds = [];
        foreach (self::DEPARTMENTS as [$code, $en, $ur, $sort]) {
            $deptIds[$code] = Department::updateOrCreate(['code' => $code], ['name_en' => $en, 'name_ur' => $ur, 'sort' => $sort])->id;
        }

        $ops = json_decode(file_get_contents(database_path('seeders/data/operations.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($ops as $op) {
            $deptCode = self::DEPARTMENTS[$op['department']][0];
            $name = $op['name'];
            $isFinish = $deptCode === 'FINISH_STENTER' && $op['kind'] === 'normal'
                && str_contains($name, 'FINISH') && ! str_starts_with($name, 'STRETCH');
            Operation::updateOrCreate(
                ['department_id' => $deptIds[$deptCode], 'name' => $name],
                [
                    'kind' => $op['kind'],
                    'stage_label' => $this->stageLabel($deptCode, $name),
                    'counts_as_finish_production' => $isFinish,
                    'finish_type' => $isFinish ? ($name === 'PRINT FINISH' ? 'print' : 'dyed') : null,
                    'is_stretch' => str_contains($name, 'STRETCH'),
                ],
            );
        }

        foreach (self::MACHINES as $code => [$name, $depts]) {
            $machine = Machine::updateOrCreate(['code' => $code], ['name' => $name]);
            $machine->departments()->sync(array_map(fn ($c) => $deptIds[$c], $depts));
        }

        Shift::updateOrCreate(['code' => 'A'], ['name' => 'Shift A', 'starts_at' => '08:00', 'ends_at' => '20:00']);
        Shift::updateOrCreate(['code' => 'B'], ['name' => 'Shift B', 'starts_at' => '20:00', 'ends_at' => '08:00']);

        foreach (self::LOOKUPS as $listKey => $values) {
            foreach ($values as $i => [$code, $label, $approved]) {
                LookupValue::updateOrCreate(
                    ['list_key' => $listKey, 'code' => $code],
                    ['label_en' => $label, 'sort' => ($i + 1) * 10, 'meta' => $listKey === 'colour_status' ? ['approved' => $approved] : null],
                );
            }
        }

        foreach (['company_name' => 'Fabric PPC', 'idle_lot_alert_days' => 3] as $key => $value) {
            if (Setting::query()->whereKey($key)->doesntExist()) {
                Setting::put($key, $value);
            }
        }
    }

    private function stageLabel(string $dept, string $op): string
    {
        return match ($dept) {
            'SINGEING' => 'SINGEING/',
            'KIER_BLEACHING' => 'BLEACHING/',
            'JIGGER_DYEING' => str_contains($op, 'DYE') ? 'DYEING/' : (str_contains($op, 'MERCERIZ') ? 'MERCERIZING/' : 'BLEACHING/'),
            'MERCERIZING' => 'MERCERIZING/',
            'BATCH_STENTER' => 'PADDING/',
            'CURING' => 'CURING/',
            'DRYING' => 'DRYING/',
            'FINISH_STENTER' => 'FINISHING/',
            'PRINTING' => 'PRINTING/',
            'SANFO_CALENDER' => 'SANFO/CAL',
            'ROLLING' => 'ROLLING/',
            'STITCHING' => 'STITCHING/',
        };
    }
}
