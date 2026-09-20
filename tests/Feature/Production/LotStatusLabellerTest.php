<?php

use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\LotRouteStep;
use App\Modules\Production\Services\LedgerService;
use App\Modules\Production\Services\LotRouteService;
use App\Modules\Production\Status\LotStatusLabeller;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RouteTemplateSeeder;

beforeEach(function () {
    $this->seed([FoundationSeeder::class, RouteTemplateSeeder::class]);
    $this->ledger = app(LedgerService::class);
    $this->routes = app(LotRouteService::class);
    $this->labeller = app(LotStatusLabeller::class);
    // Half Bleach: SINGING → SINGLE BLEACH → DRY → WHITE FINISH FOR SANFORIZING → SANFORIZE
    $this->lot = Lot::factory()->create(['po_type' => 'HALF BLEACH', 'greige_qty' => 100]);
    $this->routes->assign($this->lot);
    $this->ledger->post($this->lot, 'import', null, Position::stock(), 0, 100);
    $this->through = function (LotRouteStep $step, float $qty): void {
        $this->ledger->post($this->lot, 'process', $this->routes->previousPosition($step), $this->routes->targetPosition($step), $qty, $qty);
    };
});

// ---- rule 6 / rule 4 -------------------------------------------------------------------------------------------

it('is blank while everything is in stock, then names the next stage of the largest WIP portion', function () {
    expect($this->lot->fresh()->current_label)->toBeNull();
    $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 100, 100);
    expect($this->lot->fresh()->current_label)->toBe('SINGEING/')->and($this->lot->current_label)->toBe('SINGEING/');
    [$s1, $s2] = $this->lot->routeSteps->all();
    ($this->through)($s1, 70);
    expect($this->lot->fresh()->current_label)->toBe('BLEACHING/'); // 70 m waits for step 2, 30 m for step 1
    ($this->through)($s2, 70);
    expect($this->lot->fresh()->current_label)->toBe('DRYING/');   // 70 m at step 2's position
    ($this->through)($s1, 30);
    ($this->through)($s2, 30);
    expect($this->lot->fresh()->current_label)->toBe('DRYING/');
});

it('names the last stage as SANFO/CAL and bypasses skipped steps', function () {
    [$s1, $s2, $s3, $s4, $s5] = $this->lot->routeSteps->all();
    $this->routes->setSkipped($s2, true);
    $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 100, 100);
    ($this->through)($s1, 100);
    expect($this->lot->fresh()->current_label)->toBe('DRYING/'); // step 2 is skipped
    ($this->through)($s3, 100);
    ($this->through)($s4, 100);
    expect($this->lot->fresh()->current_label)->toBe('SANFO/CAL');
});

it('only names the stage while part of the lot is still in stock', function () {
    $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 40, 40);
    expect($this->lot->fresh()->current_label)->toBe('SINGEING/');
});

it('falls back to WIP/ for a lot without a route', function () {
    $lot = Lot::factory()->create(['po_type' => 'NONE', 'greige_qty' => 10]);
    $this->ledger->post($lot, 'import', null, Position::stock(), 0, 10);
    $this->ledger->post($lot, 'issue', Position::stock(), Position::issued(), 10, 10);
    expect($lot->routeSteps()->count())->toBe(0)->and($lot->fresh()->current_label)->toBe('WIP/');
});

// ---- rule 5 + process flag ------------------------------------------------------------------------------------

it('becomes RF-INSP when all metres are finished, and processFlag stays pending until closed or empty', function () {
    $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 100, 100);
    foreach ($this->lot->routeSteps as $step) {
        ($this->through)($step, 100);
    }
    $lot = $this->lot->fresh();
    expect($lot->current_label)->toBe('RF-INSP/')->and($this->labeller->processFlag($lot))->toBe('pending');
    $this->ledger->post($lot, 'dispatch', Position::finished(), Position::dispatched(), 100, 100);
    expect($this->labeller->processFlag($lot->fresh()))->toBe('complete');
});

it('reports a closed lot as complete whatever remains, and an untouched one as pending', function () {
    expect($this->labeller->processFlag($this->lot->fresh()))->toBe('pending');
    $this->lot->update(['state' => 'closed']);
    expect($this->labeller->processFlag($this->lot->fresh()))->toBe('complete');
});

// ---- rule 3 ---------------------------------------------------------------------------------------------------

it('shows DISP dd-mm-yy while finished or WIP metres remain after a dispatch', function () {
    $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 100, 100);
    foreach ($this->lot->routeSteps as $step) {
        ($this->through)($step, 100);
    }
    $this->ledger->post($this->lot, 'dispatch', Position::finished(), Position::dispatched(), 40, 40, ['movedAt' => now()->parse('2026-09-11 10:00:00')]);
    expect($this->lot->fresh()->current_label)->toBe('DISP 11-09-26/');
});

it('dates the label from the last dispatch that still stands', function () {
    $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 100, 100);
    foreach ($this->lot->routeSteps as $step) {
        ($this->through)($step, 100);
    }
    $this->ledger->post($this->lot, 'dispatch', Position::finished(), Position::dispatched(), 40, 40, ['movedAt' => now()->parse('2026-09-11 10:00:00')]);
    $later = $this->ledger->post($this->lot, 'dispatch', Position::finished(), Position::dispatched(), 20, 20, ['movedAt' => now()->parse('2026-09-15 10:00:00')]);
    expect($this->lot->fresh()->current_label)->toBe('DISP 15-09-26/');
    $this->ledger->reverse($later, null);
    expect($this->lot->fresh()->current_label)->toBe('DISP 11-09-26/');
});

it('shows DISP while WIP remains after a partial dispatch', function () {
    $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 100, 100);
    [$s1] = $this->lot->routeSteps->all();
    ($this->through)($s1, 100);
    $steps = $this->lot->routeSteps->all();
    // 60 m go on to the end, 40 m stay at step 2's input
    foreach (array_slice($steps, 1) as $step) {
        ($this->through)($step, 60);
    }
    $this->ledger->post($this->lot, 'dispatch', Position::finished(), Position::dispatched(), 60, 60, ['movedAt' => now()->parse('2026-08-01 08:00:00')]);
    expect($this->lot->fresh()->current_label)->toBe('DISP 01-08-26/');
});

// ---- rules 1 and 2 --------------------------------------------------------------------------------------------

it('puts the state first: HOLD, CANCELLED, CLOSED/DISPATCHED', function () {
    $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 100, 100);
    $this->lot->update(['state' => 'hold']);
    $this->ledger->relabel($this->lot);
    expect($this->lot->fresh()->current_label)->toBe('HOLD/')->and($this->lot->current_label)->toBe('HOLD/');
    $this->lot->update(['state' => 'cancelled']);
    $this->ledger->relabel($this->lot);
    expect($this->lot->fresh()->current_label)->toBe('CANCELLED/');
    $this->lot->update(['state' => 'closed']);
    $this->ledger->relabel($this->lot);
    expect($this->lot->fresh()->current_label)->toBe('CLOSED/');
    $this->lot->update(['state' => 'open']);
    foreach ($this->lot->routeSteps as $step) {
        ($this->through)($step, 100);
    }
    $this->ledger->post($this->lot, 'dispatch', Position::finished(), Position::dispatched(), 100, 100);
    $this->lot->update(['state' => 'closed']);
    $this->ledger->relabel($this->lot);
    expect($this->lot->fresh()->current_label)->toBe('DISPATCHED/');
});

it('keeps HOLD/ over every other rule and comes back to the derived label on release', function () {
    $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 100, 100);
    $this->lot->update(['state' => 'hold']);
    // a loss is still allowed on a held lot and re-labels through the ledger
    $this->ledger->post($this->lot, 'loss', Position::issued(), Position::loss(), 5, 5);
    expect($this->lot->fresh()->current_label)->toBe('HOLD/');
    $this->lot->update(['state' => 'open']);
    $this->ledger->relabel($this->lot);
    expect($this->lot->fresh()->current_label)->toBe('SINGEING/');
});

// ---- the label is the ledger's --------------------------------------------------------------------------------

it('is re-derived by rebuild from the movements', function () {
    $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 100, 100);
    Lot::query()->whereKey($this->lot->id)->update(['current_label' => 'STALE/']);
    $this->ledger->rebuild($this->lot);
    expect($this->lot->fresh()->current_label)->toBe('SINGEING/');
});
