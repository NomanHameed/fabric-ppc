<?php

namespace Database\Seeders;

use App\Modules\Foundation\Models\Operation;
use App\Modules\Foundation\Models\RouteTemplate;
use Illuminate\Database\Seeder;
use RuntimeException;

class RouteTemplateSeeder extends Seeder
{
    private const DYED = [
        ['SINGEING', 'SINGING'], ['JIGGER_DYEING', 'SCOUR+BLEACH'], ['MERCERIZING', 'MERCERIZING'],
        ['JIGGER_DYEING', 'REACTIVE DYE'], ['DRYING', 'DRY'], ['FINISH_STENTER', 'DYE FINISH FOR SANFORIZING'],
        ['SANFO_CALENDER', 'SANFORIZE'],
    ];

    /** name => [po_type, priority, steps] */
    private const TEMPLATES = [
        'Dyed - Standard' => ['DYED', 10, self::DYED],
        'Printed - Standard' => ['PRINTED', 10, [
            ['SINGEING', 'SINGING'], ['JIGGER_DYEING', 'DESIZE+BLEACH'], ['PRINTING', 'REACTIVE PRINTING'],
            ['CURING', 'REACTIVE CURE'], ['JIGGER_DYEING', 'WASHING'], ['FINISH_STENTER', 'PRINT FINISH'],
            ['SANFO_CALENDER', 'SANFORIZE'],
        ]],
        'Half Bleach' => ['HALF BLEACH', 10, [
            ['SINGEING', 'SINGING'], ['JIGGER_DYEING', 'SINGLE BLEACH'], ['DRYING', 'DRY'],
            ['FINISH_STENTER', 'WHITE FINISH FOR SANFORIZING'], ['SANFO_CALENDER', 'SANFORIZE'],
        ]],
        'Default' => [null, 0, self::DYED],
    ];

    public function run(): void
    {
        foreach (self::TEMPLATES as $name => [$poType, $priority, $steps]) {
            $template = RouteTemplate::query()->updateOrCreate(['name' => $name], ['po_type' => $poType, 'priority' => $priority]);
            $template->steps()->delete();
            foreach ($steps as $i => [$deptCode, $opName]) {
                $operation = Operation::query()->where('name', $opName)
                    ->whereHas('department', fn ($q) => $q->where('code', $deptCode))->first()
                    ?? throw new RuntimeException("Operation {$deptCode}/{$opName} not seeded");
                $template->steps()->create(['seq' => ($i + 1) * 10, 'operation_id' => $operation->id]);
            }
        }
    }
}
