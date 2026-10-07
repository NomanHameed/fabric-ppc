<?php

use App\Models\User;
use App\Modules\Foundation\Models\Stage;
use App\Modules\Foundation\Models\StageProcess;
use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Services\LedgerService;
use App\Modules\Production\Services\LotRouteService;
use App\Modules\Production\Status\StatusStageResolver;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RouteTemplateSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Support\Facades\DB;

/*
 * The reporting stage, end to end against the seeded taxonomy: that the seed maps the statuses this mill
 * actually writes, that LedgerService keeps the two columns in step with `current_label` on every write, and
 * that `production:sync-stages` backfills idempotently without touching anything else.
 */

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, FoundationSeeder::class, StageSeeder::class, RouteTemplateSeeder::class]);
    $this->ppc = User::factory()->create()->assignRole('ppc');
    $this->admin = User::factory()->create()->assignRole('admin');
    $this->ledger = app(LedgerService::class);
    $this->routes = app(LotRouteService::class);
});

/** The seeded process a status resolves to, as "STAGE_CODE/PROCESS_CODE", or null. */
function resolvedCodes(?string $status): ?string
{
    $ids = app(StatusStageResolver::class)->resolve($status);
    if ($ids['stage_process_id'] === null) {
        return null;
    }
    $process = StageProcess::query()->with('stage')->findOrFail($ids['stage_process_id']);

    return $process->stage->code.'/'.$process->code;
}

// ---- the seeded taxonomy --------------------------------------------------------------------------------------

it('seeds the stages and processes and is idempotent', function () {
    $stages = Stage::count();
    $processes = StageProcess::count();

    expect($stages)->toBe(15)->and($processes)->toBeGreaterThan(20);

    $this->seed(StageSeeder::class);

    expect(Stage::count())->toBe($stages)->and(StageProcess::count())->toBe($processes)
        ->and(Stage::where('code', 'DISPATCH')->first()->name)->toBe('Dispatch');
});

it('maps every status the labeller and the operations master can produce', function (string $status, string $expected) {
    expect(resolvedCodes($status))->toBe($expected);
})->with([
    // operations.stage_label (FoundationSeeder)
    ['SINGEING/', 'PREP/SINGEING'],
    ['BLEACHING/', 'PREP/BLEACHING'],
    ['PADDING/', 'PREP/PADDING'],
    ['MERCERIZING/', 'PREP/MERCERIZING'],
    ['DYEING/', 'DYEING/DYEING'],
    ['PRINTING/', 'PRINTING/PRINTING'],
    ['CURING/', 'PRINTING/CURING'],
    ['DRYING/', 'FINISHING/DRYING'],
    ['FINISHING/', 'FINISHING/FINISHING'],
    ['SANFO/CAL', 'FINISHING/SANFO_CAL'],
    ['SANFO/CAL RP', 'FINISHING/SANFO_CAL_RP'],
    ['ROLLING/', 'PACKING/ROLLING'],
    ['STITCHING/', 'PACKING/STITCHING'],
    // LotStatusLabeller
    ['WIP/', 'WIP/WIP_UNASSIGNED'],
    ['RF-INSP/', 'INSPECTION/FINAL_INSPECTION'],
    ['RF-DISP', 'DISPATCH/DISPATCH'],
    ['DISPATCHED/', 'DISPATCH/DISPATCH'],
    ['DISP 11-09-26/', 'DISPATCH/DISPATCH'],
    ['HOLD/', 'EXCEPTION/HOLD'],
    ['CLOSED/', 'CLOSED/CLOSED'],
    ['CANCELLED/', 'CLOSED/CANCELLED'],
    // the tracker's own codes (spec §7)
    ['ON.AAP', 'APPROVAL/APPROVAL_PENDING'],
    ['SB.ON APP', 'APPROVAL/APPROVAL_PENDING'],
    ['RTF', 'RETURN/RTF'],
    ['OUTSOURCE', 'EXTERNAL/OUTSOURCE'],
    ['LCR', 'QUALITY/LCR_REVIEW'],
    ['LCR SB REJECT', 'QUALITY/LCR_SB_REJECTED'],
    ['DEBIT', 'COMMERCIAL/DEBIT'],
]);

it('leaves a status nobody mapped unresolved', function () {
    expect(resolvedCodes('SOMETHING NEW/'))->toBeNull()
        ->and(resolvedCodes(null))->toBeNull();
});

it('ignores a deactivated process and its stage', function () {
    expect(resolvedCodes('DYEING/'))->toBe('DYEING/DYEING');

    StageProcess::query()->where('code', 'DYEING')->update(['is_active' => false]);
    app(StatusStageResolver::class)->flush();
    expect(resolvedCodes('DYEING/'))->toBeNull();

    StageProcess::query()->where('code', 'DYEING')->update(['is_active' => true]);
    Stage::query()->where('code', 'DYEING')->update(['is_active' => false]);
    app(StatusStageResolver::class)->flush();
    expect(resolvedCodes('DYEING/'))->toBeNull();
});

// ---- the ledger keeps them in step ---------------------------------------------------------------------------

it('resolves the stage in the same write as the status, at every step of a lot\'s life', function () {
    $lot = Lot::factory()->create(['po_type' => 'HALF BLEACH', 'greige_qty' => 100]);
    $this->routes->assign($lot);

    // received: everything in stock, so no status and no stage (rule 6, never guessed)
    $this->ledger->post($lot, 'import', null, Position::stock(), 0, 100);
    expect($lot->current_label)->toBeNull()
        ->and($lot->stage_id)->toBeNull()->and($lot->stage_process_id)->toBeNull();

    // issued: the label names the first step's stage, and the stage follows it
    $this->ledger->post($lot, 'issue', Position::stock(), Position::issued(), 100, 100);
    expect($lot->current_label)->not->toBeNull()
        ->and(resolvedCodes($lot->current_label))->toBe(stageCodesOf($lot));

    // processed through the first step: the label moves on, and so does the stage
    $first = $lot->routeSteps()->first();
    $this->ledger->post($lot, 'process', $this->routes->previousPosition($first), $this->routes->targetPosition($first), 100, 100);
    expect(resolvedCodes($lot->fresh()->current_label))->toBe(stageCodesOf($lot->fresh()));

    // finished: RF-INSP -> Inspection / Final Inspection
    foreach ($lot->routeSteps()->get()->skip(1) as $step) {
        $this->ledger->post($lot, 'process', $this->routes->previousPosition($step), $this->routes->targetPosition($step), 100, 100);
    }
    expect($lot->fresh()->current_label)->toBe('RF-INSP/')
        ->and(stageCodesOf($lot->fresh()))->toBe('INSPECTION/FINAL_INSPECTION');
});

it('follows a state change through relabel()', function () {
    $lot = Lot::factory()->create(['greige_qty' => 50]);
    $this->ledger->post($lot, 'import', null, Position::stock(), 0, 50);

    $lot->state = 'hold';
    $lot->save();
    $this->ledger->relabel($lot);

    expect($lot->fresh()->current_label)->toBe('HOLD/')
        ->and(stageCodesOf($lot->fresh()))->toBe('EXCEPTION/HOLD');
});

it('writes the stage on the caller\'s instance too, not only in the database', function () {
    $lot = Lot::factory()->create(['greige_qty' => 20, 'state' => 'cancelled']);
    $this->ledger->post($lot, 'import', null, Position::stock(), 0, 20);

    expect($lot->stage_process_id)->not->toBeNull()
        ->and($lot->stage_process_id)->toBe((int) DB::table('lots')->where('id', $lot->id)->value('stage_process_id'));
});

it('leaves the stage null when nothing claims the status, and fills it in once an alias is added', function () {
    $lot = Lot::factory()->create(['greige_qty' => 10, 'state' => 'cancelled']);
    StageProcess::query()->where('code', 'CANCELLED')->update(['status_aliases' => []]);
    app(StatusStageResolver::class)->flush();

    $this->ledger->post($lot, 'import', null, Position::stock(), 0, 10);
    expect($lot->current_label)->toBe('CANCELLED/')->and($lot->stage_process_id)->toBeNull();

    StageProcess::query()->where('code', 'CANCELLED')->update(['status_aliases' => json_encode(['*CANCEL*'])]);
    app(StatusStageResolver::class)->flush();
    $this->ledger->relabel($lot);

    expect(stageCodesOf($lot->fresh()))->toBe('CLOSED/CANCELLED');
});

// ---- the backfill command ------------------------------------------------------------------------------------

it('backfills existing lots, is idempotent and touches nothing else', function () {
    $lot = Lot::factory()->create(['greige_qty' => 100]);
    $this->ledger->post($lot, 'import', null, Position::stock(), 0, 100);
    $this->ledger->post($lot, 'issue', Position::stock(), Position::issued(), 100, 100);
    DB::table('lots')->where('id', $lot->id)->update(['current_label' => 'DYEING/', 'stage_id' => null, 'stage_process_id' => null]);
    $before = (array) DB::table('lots')->where('id', $lot->id)->first();

    $this->artisan('production:sync-stages')->assertSuccessful();

    $after = (array) DB::table('lots')->where('id', $lot->id)->first();
    expect(stageCodesOf($lot->fresh()))->toBe('DYEING/DYEING');
    // only the two stage columns changed: no quantity, no label, not even updated_at
    foreach ($before as $column => $value) {
        if (! in_array($column, ['stage_id', 'stage_process_id'], true)) {
            expect($after[$column])->toEqual($value, "column {$column} was changed");
        }
    }

    // a second run has nothing to do
    $this->artisan('production:sync-stages')
        ->expectsOutputToContain('Updated 0 of')
        ->assertSuccessful();
});

it('writes nothing with --pretend but still reports what it would do', function () {
    $lot = Lot::factory()->create(['greige_qty' => 100]);
    DB::table('lots')->where('id', $lot->id)->update(['current_label' => 'DYEING/']);

    $this->artisan('production:sync-stages --pretend')
        ->expectsOutputToContain('Would update 1 of')
        ->assertSuccessful();

    expect($lot->fresh()->stage_process_id)->toBeNull();
});

it('reports the statuses it could not map', function () {
    $lot = Lot::factory()->create(['greige_qty' => 100]);
    DB::table('lots')->where('id', $lot->id)->update(['current_label' => 'NOT A STATUS/']);

    $this->artisan('production:sync-stages')
        ->expectsOutputToContain('1 status(es) no stage process claims')
        ->assertSuccessful();

    expect($lot->fresh()->stage_process_id)->toBeNull();
});

it('fails rather than guessing an actor when there is no user to attribute the run to', function () {
    User::query()->delete();

    $this->artisan('production:sync-stages')->assertFailed();
});

/** The lot's own stage/process as "STAGE_CODE/PROCESS_CODE", read back from the columns. */
function stageCodesOf(Lot $lot): ?string
{
    if ($lot->stage_process_id === null) {
        return null;
    }
    $process = StageProcess::query()->with('stage')->findOrFail($lot->stage_process_id);

    return $process->stage->code.'/'.$process->code;
}
