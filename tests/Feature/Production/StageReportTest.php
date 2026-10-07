<?php

use App\Modules\Foundation\Models\Stage;
use App\Modules\Foundation\Models\StageProcess;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Services\ProductionAnalyticsService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Support\Facades\DB;

/*
 * The report's stage -> stage process grouping (spec §13, §14): the six quantities per process, each stage
 * the sum of its own processes, and — the property the report depends on — every lot counted exactly once.
 */

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, StageSeeder::class]);
    $this->analytics = app(ProductionAnalyticsService::class);
});

/** A lot sitting at $code with the given quantities, written straight onto the derived columns. */
function lotAt(?string $code, array $quantities, string $state = 'open'): Lot
{
    $lot = Lot::factory()->create(['greige_qty' => $quantities['greige_qty'] ?? 0, 'state' => $state]);
    $process = $code === null ? null : StageProcess::query()->where('code', $code)->firstOrFail();
    DB::table('lots')->where('id', $lot->id)->update($quantities + [
        'stage_id' => $process?->stage_id,
        'stage_process_id' => $process?->getKey(),
    ]);

    return $lot->fresh();
}

/** One row of the grouping, by stage code. */
function stageRow(array $rows, ?string $code): array
{
    foreach ($rows as $row) {
        if ($row['code'] === ($code ?? 'UNCLASSIFIED')) {
            return $row;
        }
    }

    throw new RuntimeException('no row for '.($code ?? 'UNCLASSIFIED'));
}

it('groups the lots stage by stage and process by process', function () {
    lotAt('DYEING', ['greige_qty' => 100, 'wip_qty' => 100]);
    lotAt('DYEING', ['greige_qty' => 50, 'wip_qty' => 20, 'finished_qty' => 30]);
    lotAt('SANFO_CAL', ['greige_qty' => 200, 'wip_qty' => 200]);
    lotAt('DRYING', ['greige_qty' => 10, 'wip_qty' => 10]);

    $rows = $this->analytics->byStage();

    expect(array_column($rows, 'code'))->toHaveCount(Stage::count() + 1)      // every stage, plus unclassified
        ->and(stageRow($rows, 'DYEING')['lots'])->toBe(2)
        ->and(stageRow($rows, 'DYEING')['wip_qty'])->toBe(120.0)
        ->and(stageRow($rows, 'DYEING')['finished_qty'])->toBe(30.0)
        ->and(stageRow($rows, 'DYEING')['greige_qty'])->toBe(150.0);

    // the stage is the sum of its processes, and each process is its own row
    $finishing = stageRow($rows, 'FINISHING');
    $byProcess = collect($finishing['processes'])->keyBy('code');
    expect($finishing['wip_qty'])->toBe(210.0)
        ->and($byProcess['SANFO_CAL']['wip_qty'])->toBe(200.0)
        ->and($byProcess['DRYING']['wip_qty'])->toBe(10.0)
        ->and($byProcess['FINISHING']['wip_qty'])->toBe(0.0)                  // a process with nothing on it is still a row
        ->and($byProcess['SANFO_CAL']['lots'])->toBe(1);
});

it('counts every lot exactly once, so the stage split adds back up to the mill', function () {
    lotAt('DYEING', ['greige_qty' => 100, 'wip_qty' => 100]);
    lotAt('SANFO_CAL', ['greige_qty' => 200, 'wip_qty' => 150, 'finished_qty' => 50]);
    lotAt('DISPATCH', ['greige_qty' => 300, 'dispatched_qty' => 300]);
    lotAt(null, ['greige_qty' => 400, 'stock_qty' => 400]);                   // no stage: still counted

    $rows = $this->analytics->byStage();
    $mill = DB::table('lots')->whereIn('state', ['open', 'hold'])
        ->selectRaw('count(*) lots, sum(greige_qty) g, sum(wip_qty) w, sum(finished_qty) f, sum(dispatched_qty) d, sum(stock_qty) s')
        ->first();

    expect(array_sum(array_column($rows, 'lots')))->toBe((int) $mill->lots)
        ->and(array_sum(array_column($rows, 'greige_qty')))->toBe((float) $mill->g)
        ->and(array_sum(array_column($rows, 'wip_qty')))->toBe((float) $mill->w)
        ->and(array_sum(array_column($rows, 'finished_qty')))->toBe((float) $mill->f)
        ->and(array_sum(array_column($rows, 'dispatched_qty')))->toBe((float) $mill->d)
        ->and(array_sum(array_column($rows, 'stock_qty')))->toBe((float) $mill->s);
});

it('keeps lots with no stage, and lots pointing at a process that is gone, in the unclassified row', function () {
    lotAt(null, ['greige_qty' => 400, 'stock_qty' => 400]);
    $orphan = lotAt('DYEING', ['greige_qty' => 70, 'wip_qty' => 70]);
    // the process was deleted after the lot was grouped (nullOnDelete empties the column)
    DB::table('lots')->where('id', $orphan->id)->update(['stage_process_id' => null]);

    $unclassified = stageRow($this->analytics->byStage(), null);

    expect($unclassified['id'])->toBeNull()
        ->and($unclassified['lots'])->toBe(2)
        ->and($unclassified['stock_qty'])->toBe(400.0)
        ->and($unclassified['wip_qty'])->toBe(70.0);
});

it('counts the states it is asked for, and every state when asked for none', function () {
    lotAt('DYEING', ['greige_qty' => 100, 'wip_qty' => 100]);
    lotAt('DISPATCH', ['greige_qty' => 60, 'dispatched_qty' => 60], 'closed');

    expect(stageRow($this->analytics->byStage(), 'DISPATCH')['lots'])->toBe(0)         // closed: out by default
        ->and(stageRow($this->analytics->byStage(null), 'DISPATCH')['lots'])->toBe(1)  // every state
        ->and(stageRow($this->analytics->byStage(['closed']), 'DYEING')['lots'])->toBe(0);
});

it('shows every stage and process even with no lots at all, so the report keeps its shape', function () {
    $rows = $this->analytics->byStage();

    expect($rows)->toHaveCount(Stage::count() + 1);
    foreach ($rows as $row) {
        expect($row['lots'])->toBe(0)->and($row['processes'])->not->toBeEmpty();
    }
    expect(stageRow($rows, 'PREP')['processes'])->toHaveCount(4);
});
