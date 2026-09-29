<?php

/*
 * `colour_overrides.approved_at` records when a decision was taken. It must never move on its own.
 *
 * It was declared `$t->timestamp('approved_at')` and is the first NOT NULL TIMESTAMP column of its table, so
 * on MariaDB 10.4 (and on MySQL with `explicit_defaults_for_timestamp` off) it silently acquired
 * `ON UPDATE CURRENT_TIMESTAMP`: re-stamping the reason, or nulling `production_line_id` when a sheet line is
 * deleted, rewrote when the override had been approved. It is a `dateTime` now — the same fix 628dc9c made
 * for `user_devices` and `user_actions`.
 */

use App\Models\User;
use App\Modules\Production\Models\ColourOverride;
use App\Modules\Production\Models\Lot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('does not rewrite approved_at when the override row is updated', function () {
    $approver = User::factory()->create();
    $lot = Lot::factory()->create();
    $approvedAt = CarbonImmutable::now()->subDays(9)->startOfMinute();

    $override = ColourOverride::query()->create([
        'lot_id' => $lot->id,
        'reason' => 'Party confirmed the shade on the phone.',
        'approved_by' => $approver->id,
        'approved_at' => $approvedAt,
    ]);

    $override->update(['reason' => 'Written approval received.']);
    $override->update(['production_line_id' => null]);

    expect($override->fresh()->approved_at->toDateTimeString())->toBe($approvedAt->toDateTimeString());
});

it('declares approved_at without an ON UPDATE clause', function () {
    if (DB::connection()->getDriverName() !== 'mysql') {
        test()->markTestSkipped('The implicit ON UPDATE CURRENT_TIMESTAMP rule is a MySQL/MariaDB behaviour.');
    }

    expect(Schema::hasColumn('colour_overrides', 'approved_at'))->toBeTrue();

    $column = collect(DB::select('SHOW COLUMNS FROM colour_overrides'))
        ->firstWhere('Field', 'approved_at');

    expect(strtolower((string) $column->Extra))->not->toContain('on update')
        ->and(strtolower((string) $column->Type))->toBe('datetime');
});
