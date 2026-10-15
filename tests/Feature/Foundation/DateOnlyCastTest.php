<?php

use App\Modules\Foundation\Casts\DateOnly;
use App\Modules\Production\Models\Contract;
use App\Modules\Production\Models\MachineAllocation;
use App\Modules\Production\Models\ProductionSheet;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * `App\Modules\Foundation\Casts\DateOnly` on the schema's three DATE columns.
 *
 * The storage assertions read the **raw** column with the query builder, never the cast attribute: Laravel's
 * built-in `date` cast wrote 'Y-m-d H:i:s', which MySQL truncates to a date and SQLite keeps verbatim, so
 * only the stored value tells the two drivers apart. A regression on either driver has to fail here.
 */

beforeEach(function () {
    // one row per DATE column, all three given the same value
    $this->rows = fn (mixed $value): array => [
        Contract::factory()->create(['po_date' => $value]),
        ProductionSheet::factory()->create(['sheet_date' => $value]),
        MachineAllocation::factory()->create(['allocated_on' => $value]),
    ];
    $this->stored = fn (array $rows): array => [
        DB::table('contracts')->where('id', $rows[0]->id)->value('po_date'),
        DB::table('production_sheets')->where('id', $rows[1]->id)->value('sheet_date'),
        DB::table('machine_allocations')->where('id', $rows[2]->id)->value('allocated_on'),
    ];
});

it('stores a date-only value whatever string the attribute was given', function (string $value) {
    expect(($this->stored)(($this->rows)($value)))->toBe(['2026-09-11', '2026-09-11', '2026-09-11']);
})->with([
    'date' => '2026-09-11',
    'datetime' => '2026-09-11 14:30:00',
    'midnight' => '2026-09-11 00:00:00',
    'end of day' => '2026-09-11 23:59:59',
]);

it('stores a date-only value from a Carbon instance, immutable or not', function () {
    expect(($this->stored)(($this->rows)(CarbonImmutable::parse('2026-09-11 23:59:59'))))->toBe(['2026-09-11', '2026-09-11', '2026-09-11'])
        ->and(($this->stored)(($this->rows)(Carbon::parse('2026-09-11 06:05:00'))))->toBe(['2026-09-11', '2026-09-11', '2026-09-11']);
});

it('reads the column back as a start-of-day CarbonImmutable', function () {
    [$contract, $sheet, $allocation] = ($this->rows)('2026-09-11 14:30:00');

    foreach ([$contract->fresh()->po_date, $sheet->fresh()->sheet_date, $allocation->fresh()->allocated_on] as $read) {
        expect($read)->toBeInstanceOf(CarbonImmutable::class)
            ->and($read->toDateTimeString())->toBe('2026-09-11 00:00:00')
            ->and($read->format('d-m-Y'))->toBe('11-09-2026');
    }
});

it('passes null and the empty string through untouched', function () {
    $cast = new DateOnly;
    $model = new Contract;

    expect($cast->set($model, 'po_date', null, []))->toBeNull()
        ->and($cast->set($model, 'po_date', '', []))->toBeNull()
        ->and($cast->get($model, 'po_date', null, []))->toBeNull()
        ->and($cast->get($model, 'po_date', '', []))->toBeNull();
});

it('lets a plain Y-m-d equality find the row on either driver', function () {
    // the shape `ProductionSheetController`'s uniqueness probe and `QueryCriteria`'s `date` criterion both use,
    // kept index-friendly by never wrapping the column in a function
    [$contract, $sheet, $allocation] = ($this->rows)(CarbonImmutable::parse('2026-09-11 14:30:00'));

    expect(Contract::query()->where('po_date', '2026-09-11')->pluck('id')->all())->toBe([$contract->id])
        ->and(ProductionSheet::query()->where('sheet_date', '2026-09-11')->pluck('id')->all())->toBe([$sheet->id])
        ->and(MachineAllocation::query()->where('allocated_on', '2026-09-11')->pluck('id')->all())->toBe([$allocation->id])
        ->and(MachineAllocation::query()->between('2026-09-11', '2026-09-11')->pluck('id')->all())->toBe([$allocation->id])
        // a BETWEEN whose last day is the row's own day must keep it
        ->and(MachineAllocation::query()->between('2026-09-10', '2026-09-11')->pluck('id')->all())->toBe([$allocation->id])
        ->and(ProductionSheet::query()->whereBetween('sheet_date', ['2026-09-10', '2026-09-11'])->pluck('id')->all())->toBe([$sheet->id]);
});
