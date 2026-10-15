<?php

use App\Modules\Foundation\Models\Stage;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Services\ProductionAnalyticsService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Support\Facades\DB;

/*
 * The report's stage grouping (spec §13, §14): the six quantities per stage and — the property the report
 * depends on — every lot counted exactly once.
 *
 * One grouping level: `lots.stage_process_id` is gone and StatusStageResolver resolves a status to a stage
 * and nothing finer, so the report has stage subtotals and a grand total.
 */

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, StageSeeder::class]);
    $this->analytics = app(ProductionAnalyticsService::class);
});

/** A lot sitting at the stage with the given code, with the quantities written straight onto the lot. */
function lotAt(?string $code, array $quantities, string $state = 'open'): Lot
{
    $lot = Lot::factory()->create(['greige_received_oracle' => $quantities['greige_received_oracle'] ?? 0, 'state' => $state]);
    $stage = $code === null ? null : Stage::query()->where('code', $code)->firstOrFail();
    DB::table('lots')->where('id', $lot->id)->update($quantities + ['stage_id' => $stage?->getKey()]);

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

it('groups the lots stage by stage', function () {
    lotAt('DYEING', ['greige_received_oracle' => 100, 'wip' => 100]);
    lotAt('DYEING', ['greige_received_oracle' => 50, 'wip' => 20, 'finished' => 30]);
    lotAt('FINISHING', ['greige_received_oracle' => 200, 'wip' => 200]);
    lotAt('FINISHING', ['greige_received_oracle' => 10, 'wip' => 10]);

    $rows = $this->analytics->byStage();

    expect(array_column($rows, 'code'))->toHaveCount(Stage::count() + 1)      // every stage, plus unclassified
        ->and(stageRow($rows, 'DYEING')['lots'])->toBe(2)
        ->and(stageRow($rows, 'DYEING')['wip'])->toBe(120.0)
        ->and(stageRow($rows, 'DYEING')['finished'])->toBe(30.0)
        ->and(stageRow($rows, 'DYEING')['greige_received_oracle'])->toBe(150.0)
        // the lots of one stage are summed into that stage's single row
        ->and(stageRow($rows, 'FINISHING')['wip'])->toBe(210.0)
        ->and(stageRow($rows, 'FINISHING')['lots'])->toBe(2)
        // a stage with nothing on it is still a row, so the report keeps its shape
        ->and(stageRow($rows, 'PRINTING')['lots'])->toBe(0)
        ->and(stageRow($rows, 'PRINTING')['wip'])->toBe(0.0);
});

it('counts every lot exactly once, so the stage split adds back up to the mill', function () {
    lotAt('DYEING', ['greige_received_oracle' => 100, 'wip' => 100]);
    lotAt('FINISHING', ['greige_received_oracle' => 200, 'wip' => 150, 'finished' => 50]);
    lotAt('DISPATCH', ['greige_received_oracle' => 300, 'dispatch' => 300]);
    lotAt(null, ['greige_received_oracle' => 400, 'greige_in_stock_oracle' => 400]);                   // no stage: still counted

    $rows = $this->analytics->byStage();
    $mill = DB::table('lots')->whereIn('state', ['open', 'hold'])
        ->selectRaw('count(*) lots, sum(greige_received_oracle) g, sum(wip) w, sum(finished) f, sum(dispatch) d, sum(greige_in_stock_oracle) s')
        ->first();

    expect(array_sum(array_column($rows, 'lots')))->toBe((int) $mill->lots)
        ->and(array_sum(array_column($rows, 'greige_received_oracle')))->toBe((float) $mill->g)
        ->and(array_sum(array_column($rows, 'wip')))->toBe((float) $mill->w)
        ->and(array_sum(array_column($rows, 'finished')))->toBe((float) $mill->f)
        ->and(array_sum(array_column($rows, 'dispatch')))->toBe((float) $mill->d)
        ->and(array_sum(array_column($rows, 'greige_in_stock_oracle')))->toBe((float) $mill->s);
});

it('keeps lots with no stage, and lots pointing at a stage that is gone, in the unclassified row', function () {
    lotAt(null, ['greige_received_oracle' => 400, 'greige_in_stock_oracle' => 400]);
    $orphan = lotAt('DYEING', ['greige_received_oracle' => 70, 'wip' => 70]);
    // the stage was deleted after the lot was grouped (nullOnDelete empties the column)
    DB::table('lots')->where('id', $orphan->id)->update(['stage_id' => null]);

    $unclassified = stageRow($this->analytics->byStage(), null);

    expect($unclassified['id'])->toBeNull()
        ->and($unclassified['lots'])->toBe(2)
        ->and($unclassified['greige_in_stock_oracle'])->toBe(400.0)
        ->and($unclassified['wip'])->toBe(70.0);
});

it('counts the states it is asked for, and every state when asked for none', function () {
    lotAt('DYEING', ['greige_received_oracle' => 100, 'wip' => 100]);
    lotAt('DISPATCH', ['greige_received_oracle' => 60, 'dispatch' => 60], 'closed');

    expect(stageRow($this->analytics->byStage(), 'DISPATCH')['lots'])->toBe(0)         // closed: out by default
        ->and(stageRow($this->analytics->byStage(null), 'DISPATCH')['lots'])->toBe(1)  // every state
        ->and(stageRow($this->analytics->byStage(['closed']), 'DYEING')['lots'])->toBe(0);
});

it('shows every stage even with no lots at all, so the report keeps its shape', function () {
    $rows = $this->analytics->byStage();

    expect($rows)->toHaveCount(Stage::count() + 1);
    foreach ($rows as $row) {
        expect($row['lots'])->toBe(0)
            // one level only: a stage row carries its own totals and no nested processes
            ->and($row)->not->toHaveKey('processes');
    }
});
