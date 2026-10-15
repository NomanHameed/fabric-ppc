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
 * actually writes, that LedgerService keeps `stage_id` in step with `status_oracle` on every write, and that
 * `production:sync-stages` backfills idempotently without touching anything else.
 *
 * Resolution is **stage-only**: `lots.stage_process_id` is gone and the aliases live on
 * `stages.status_aliases`, so a status resolves to a stage code and nothing finer.
 */

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, FoundationSeeder::class, StageSeeder::class, RouteTemplateSeeder::class]);
    $this->ppc = User::factory()->create()->assignRole('ppc');
    $this->admin = User::factory()->create()->assignRole('admin');
    $this->ledger = app(LedgerService::class);
    $this->routes = app(LotRouteService::class);
});

/** The seeded stage a status resolves to, as its code, or null. */
function resolvedCode(?string $status): ?string
{
    $stageId = app(StatusStageResolver::class)->stageId($status);

    return $stageId === null ? null : Stage::query()->findOrFail($stageId)->code;
}

// ---- the seeded taxonomy --------------------------------------------------------------------------------------

it('seeds the user\'s own stage list and is idempotent', function () {
    $codes = Stage::query()->orderBy('sort_order')->pluck('code')->all();

    // Spec §19, in the user's own order. Greige and Finished are stages; Packing and Work in Process, which
    // earlier seeds invented, are not.
    expect($codes)->toBe([
        'GREIGE', 'PREP', 'DYEING', 'PRINTING', 'FINISHING', 'INSPECTION', 'APPROVAL', 'FINISHED',
        'DISPATCH', 'EXCEPTION', 'RETURN', 'EXTERNAL', 'QUALITY', 'COMMERCIAL', 'CLOSED',
    ])
        ->and(Stage::query()->whereIn('code', ['PACKING', 'WIP'])->count())->toBe(0)
        // the processes earlier seeds invented under a stage the user does keep
        ->and(StageProcess::query()->whereIn('code', ['SINGEING', 'MERCERIZING', 'CURING', 'DRYING', 'CLOSED'])->count())->toBe(0);

    $stages = Stage::count();
    $processes = StageProcess::count();
    $this->seed(StageSeeder::class);

    expect(Stage::count())->toBe($stages)->and(StageProcess::count())->toBe($processes)
        ->and(Stage::where('code', 'DISPATCH')->first()->name)->toBe('Dispatch');
});

it('maps every status the labeller and the operations master can produce', function (?string $status, string $expected) {
    expect(resolvedCode($status))->toBe($expected);
})->with([
    // operations.stage_label (FoundationSeeder). Singeing, mercerizing and padding are preparation; drying,
    // rolling and stitching are finishing-floor work; curing follows printing.
    ['SINGEING/', 'PREP'],
    ['BLEACHING/', 'PREP'],
    ['PADDING/', 'PREP'],
    ['MERCERIZING/', 'PREP'],
    ['DYEING/', 'DYEING'],
    ['PRINTING/', 'PRINTING'],
    ['CURING/', 'PRINTING'],
    ['DRYING/', 'FINISHING'],
    ['FINISHING/', 'FINISHING'],
    ['SANFO/CAL', 'FINISHING'],
    ['SANFO/CAL RP', 'FINISHING'],
    ['ROLLING/', 'FINISHING'],
    ['STITCHING/', 'FINISHING'],
    // LotStatusLabeller. `RF-INSP/` means "nothing on the floor, the metres are finished", which is the
    // Finished stage; Inspection waits for an inspection status the mill does not write yet.
    ['RF-INSP/', 'FINISHED'],
    ['RF-DISP', 'DISPATCH'],
    ['DISPATCHED/', 'DISPATCH'],
    ['DISP 11-09-26/', 'DISPATCH'],
    ['HOLD/', 'EXCEPTION'],
    ['CLOSED/', 'CLOSED'],
    ['CANCELLED/', 'CLOSED'],
    // no status at all: a lot that has never been issued is greige, through the `#BLANK` sentinel
    [null, 'GREIGE'],
    ['', 'GREIGE'],
    // the tracker's own codes (spec §7)
    ['ON.AAP', 'APPROVAL'],
    ['SB.ON APP', 'APPROVAL'],
    ['RTF', 'RETURN'],
    ['OUTSOURCE', 'EXTERNAL'],
    ['LCR', 'QUALITY'],
    ['LCR SB REJECT', 'QUALITY'],
    ['DEBIT', 'COMMERCIAL'],
]);

it('leaves a status nobody mapped unresolved', function () {
    expect(resolvedCode('SOMETHING NEW/'))->toBeNull();
});

it('leaves the Inspection stage unclaimed until the mill writes an inspection status', function () {
    // Deliberate: the user's list has the stage, and no status the mill writes today means it. A stage with
    // no alias simply never claims a lot — it is not a mapping gap to guess at.
    expect(Stage::query()->where('code', 'INSPECTION')->firstOrFail()->aliasList())->toBe([]);
});

it('ignores a deactivated stage', function () {
    expect(resolvedCode('DYEING/'))->toBe('DYEING');

    Stage::query()->where('code', 'DYEING')->update(['is_active' => false]);
    app(StatusStageResolver::class)->flush();
    expect(resolvedCode('DYEING/'))->toBeNull();
});

// ---- the ledger keeps them in step ---------------------------------------------------------------------------

it('resolves the stage in the same write as the status, at every step of a lot\'s life', function () {
    $lot = Lot::factory()->create(['po_type_oracle' => 'HALF BLEACH', 'greige_received_oracle' => 100]);
    $this->routes->assign($lot);

    // received: everything in stock, so no status (rule 6) — and Greige claims exactly that lot
    $this->ledger->post($lot, 'import', null, Position::stock(), 0, 100);
    expect($lot->status_oracle)->toBeNull()
        ->and(stageCodeOf($lot))->toBe('GREIGE');

    // issued: the label names the first step's stage, and the stage follows it
    $this->ledger->post($lot, 'issue', Position::stock(), Position::issued(), 100, 100);
    expect($lot->status_oracle)->not->toBeNull()
        ->and(resolvedCode($lot->status_oracle))->toBe(stageCodeOf($lot));

    // processed through the first step: the label moves on, and so does the stage
    $first = $lot->routeSteps()->first();
    $this->ledger->post($lot, 'process', $this->routes->previousPosition($first), $this->routes->targetPosition($first), 100, 100);
    expect(resolvedCode($lot->fresh()->status_oracle))->toBe(stageCodeOf($lot->fresh()));

    // finished: RF-INSP -> Finished
    foreach ($lot->routeSteps()->get()->skip(1) as $step) {
        $this->ledger->post($lot, 'process', $this->routes->previousPosition($step), $this->routes->targetPosition($step), 100, 100);
    }
    expect($lot->fresh()->status_oracle)->toBe('RF-INSP/')
        ->and(stageCodeOf($lot->fresh()))->toBe('FINISHED');
});

it('follows a state change through relabel()', function () {
    $lot = Lot::factory()->create(['greige_received_oracle' => 50]);
    $this->ledger->post($lot, 'import', null, Position::stock(), 0, 50);

    $lot->state = 'hold';
    $lot->save();
    $this->ledger->relabel($lot);

    expect($lot->fresh()->status_oracle)->toBe('HOLD/')
        ->and(stageCodeOf($lot->fresh()))->toBe('EXCEPTION');
});

it('writes the stage on the caller\'s instance too, not only in the database', function () {
    $lot = Lot::factory()->create(['greige_received_oracle' => 20, 'state' => 'cancelled']);
    $this->ledger->post($lot, 'import', null, Position::stock(), 0, 20);

    expect($lot->stage_id)->not->toBeNull()
        ->and($lot->stage_id)->toBe((int) DB::table('lots')->where('id', $lot->id)->value('stage_id'));
});

it('leaves the stage null when nothing claims the status, and fills it in once an alias is added', function () {
    $lot = Lot::factory()->create(['greige_received_oracle' => 10, 'state' => 'cancelled']);
    Stage::query()->where('code', 'CLOSED')->update(['status_aliases' => json_encode([])]);
    app(StatusStageResolver::class)->flush();

    $this->ledger->post($lot, 'import', null, Position::stock(), 0, 10);
    expect($lot->status_oracle)->toBe('CANCELLED/')->and($lot->stage_id)->toBeNull();

    Stage::query()->where('code', 'CLOSED')->update(['status_aliases' => json_encode(['*CANCEL*'])]);
    app(StatusStageResolver::class)->flush();
    $this->ledger->relabel($lot);

    expect(stageCodeOf($lot->fresh()))->toBe('CLOSED');
});

// ---- the backfill command ------------------------------------------------------------------------------------

it('backfills existing lots, is idempotent and touches nothing else', function () {
    $lot = Lot::factory()->create(['greige_received_oracle' => 100]);
    $this->ledger->post($lot, 'import', null, Position::stock(), 0, 100);
    $this->ledger->post($lot, 'issue', Position::stock(), Position::issued(), 100, 100);
    DB::table('lots')->where('id', $lot->id)->update(['status_oracle' => 'DYEING/', 'stage_id' => null]);
    $before = (array) DB::table('lots')->where('id', $lot->id)->first();

    $this->artisan('production:sync-stages')->assertSuccessful();

    $after = (array) DB::table('lots')->where('id', $lot->id)->first();
    expect(stageCodeOf($lot->fresh()))->toBe('DYEING');
    // only the stage column changed: no quantity, no label, not even updated_at
    foreach ($before as $column => $value) {
        if ($column !== 'stage_id') {
            expect($after[$column])->toEqual($value, "column {$column} was changed");
        }
    }

    // a second run has nothing to do
    $this->artisan('production:sync-stages')
        ->expectsOutputToContain('Updated 0 of')
        ->assertSuccessful();
});

it('writes nothing with --pretend but still reports what it would do', function () {
    $lot = Lot::factory()->create(['greige_received_oracle' => 100]);
    DB::table('lots')->where('id', $lot->id)->update(['status_oracle' => 'DYEING/', 'stage_id' => null]);

    $this->artisan('production:sync-stages --pretend')
        ->expectsOutputToContain('Would update 1 of')
        ->assertSuccessful();

    expect($lot->fresh()->stage_id)->toBeNull();
});

it('reports the statuses it could not map', function () {
    $lot = Lot::factory()->create(['greige_received_oracle' => 100]);
    DB::table('lots')->where('id', $lot->id)->update(['status_oracle' => 'NOT A STATUS/', 'stage_id' => null]);

    $this->artisan('production:sync-stages')
        ->expectsOutputToContain('1 status(es) no stage claims')
        ->assertSuccessful();

    expect($lot->fresh()->stage_id)->toBeNull();
});

it('fails rather than guessing an actor when there is no user to attribute the run to', function () {
    User::query()->delete();

    $this->artisan('production:sync-stages')->assertFailed();
});

/** The lot's own stage code, read back from the column. */
function stageCodeOf(Lot $lot): ?string
{
    return $lot->stage_id === null ? null : Stage::query()->findOrFail($lot->stage_id)->code;
}
