<?php

use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\LookupValue;
use App\Modules\Foundation\Models\Machine;
use App\Modules\Foundation\Models\Operation;
use App\Modules\Foundation\Models\Setting;
use App\Modules\Foundation\Models\Shift;
use Database\Seeders\FoundationSeeder;

beforeEach(fn () => $this->seed(FoundationSeeder::class));

it('seeds the 12 cost centres', function () {
    expect(Department::count())->toBe(12)
        ->and(Department::where('code', 'SANFO_CALENDER')->value('name_en'))->toBe('Sanforizing / Calender');
});

it('seeds all 112 operations with kinds', function () {
    expect(Operation::count())->toBe(112)
        ->and(Operation::where('kind', 'reprocess')->count())->toBe(24);
});

it('flags the 13 fresh stenter finish operations as finish production', function () {
    $names = Operation::where('counts_as_finish_production', true)->orderBy('name')->pluck('name')->all();
    expect($names)->toHaveCount(13)
        ->toContain('PRINT FINISH', 'DYE FINISH', 'WHITE FINISH FOR SANFORIZING')
        ->not->toContain('STRETCH FOR FINISH', 'RE FINISH');
    expect(Operation::where('name', 'PRINT FINISH')->value('finish_type'))->toBe('print')
        ->and(Operation::where('name', 'DYE FINISH')->value('finish_type'))->toBe('dyed');
});

it('derives stage labels from the department', function () {
    $label = fn (string $dept, string $op) => Operation::whereHas('department', fn ($q) => $q->where('code', $dept))
        ->where('name', $op)->value('stage_label');
    expect($label('JIGGER_DYEING', 'REACTIVE DYE'))->toBe('DYEING/')
        ->and($label('JIGGER_DYEING', 'SCOUR+BLEACH'))->toBe('BLEACHING/')
        ->and($label('FINISH_STENTER', 'STENTER FINISH'))->toBe('FINISHING/')
        ->and($label('SANFO_CALENDER', 'SANFORIZE'))->toBe('SANFO/CAL');
});

it('marks stretch operations', function () {
    expect(Operation::where('is_stretch', true)->count())->toBe(6);
});

it('seeds machines linked to departments', function () {
    $stenter = Machine::where('code', 'STENTER-1')->firstOrFail();
    expect($stenter->departments->pluck('code')->sort()->values()->all())->toBe(['BATCH_STENTER', 'FINISH_STENTER'])
        ->and(Machine::count())->toBe(10);
});

it('seeds shifts, lookups and settings', function () {
    expect(Shift::pluck('code')->all())->toBe(['A', 'B'])
        ->and(LookupValue::list('colour_status')->where('meta->approved', true)->pluck('code')->sort()->values()->all())
        ->toBe(['APPROVED', 'DIRECT_PROD', 'MWS_APP'])
        ->and(LookupValue::list('po_type')->count())->toBe(6)
        ->and(Setting::get('company_name'))->toBe('Fabric PPC')
        ->and(Setting::get('missing', 'dflt'))->toBe('dflt');
});

it('is idempotent', function () {
    $this->seed(FoundationSeeder::class);
    expect(Operation::count())->toBe(112)->and(Department::count())->toBe(12);
});
