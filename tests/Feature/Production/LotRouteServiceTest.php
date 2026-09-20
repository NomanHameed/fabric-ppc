<?php

use App\Modules\Foundation\Models\Operation;
use App\Modules\Foundation\Models\RouteTemplate;
use App\Modules\Foundation\Models\Setting;
use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\LotRouteStep;
use App\Modules\Production\Services\LedgerService;
use App\Modules\Production\Services\LotRouteService;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RouteTemplateSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed([FoundationSeeder::class, RouteTemplateSeeder::class]);
    $this->routes = app(LotRouteService::class);
    $this->ledger = app(LedgerService::class);
});

it('assigns the matching template by po_type', function () {
    $lot = Lot::factory()->create(['po_type' => 'PRINTED']);
    expect($this->routes->assign($lot))->toBe(7)
        ->and($lot->routeSteps()->count())->toBe(7)
        ->and($lot->routeSteps->first()->operation->name)->toBe('SINGING')
        ->and($lot->routeSteps->first()->seq)->toBe(10)
        ->and($lot->routeSteps->pluck('status')->unique()->all())->toBe(['pending'])
        ->and($lot->routeSteps->pluck('is_added')->unique()->all())->toBe([false]);
});

it('falls back to the settings default template, then to nothing', function () {
    RouteTemplate::query()->update(['is_active' => false]);
    $lot = Lot::factory()->create(['po_type' => 'UNKNOWN']);
    expect($this->routes->assign($lot))->toBe(0);
    $t = RouteTemplate::where('name', 'Half Bleach')->first();
    $t->update(['is_active' => true, 'po_type' => 'NEVER']);
    Setting::put('default_route_template_id', $t->id);
    expect($this->routes->assign($lot))->toBe(5);
});

it('does not re-assign an existing route unless asked, and never one with progress', function () {
    $lot = Lot::factory()->create(['po_type' => 'DYED']);
    $this->routes->assign($lot);
    expect($this->routes->assign($lot))->toBe(0);
    $this->ledger->post($lot, 'import', null, Position::stock(), 0, 100);
    $this->ledger->post($lot, 'issue', Position::stock(), Position::issued(), 100, 100);
    $first = $lot->routeSteps->first();
    $this->ledger->post($lot, 'process', $this->routes->previousPosition($first), $this->routes->targetPosition($first), 100, 100);
    $this->routes->refreshStatuses($lot);
    expect(fn () => $this->routes->assign($lot, null, replace: true))->toThrow(DomainException::class);
});

it('re-assigns a route without progress when asked', function () {
    $lot = Lot::factory()->create(['po_type' => 'DYED']);
    $this->routes->assign($lot);
    $t = RouteTemplate::where('name', 'Half Bleach')->first();
    expect($this->routes->assign($lot, $t, replace: true))->toBe(5)->and($lot->routeSteps()->count())->toBe(5);
});

it('derives previous and target positions, bypassing skipped steps', function () {
    $lot = Lot::factory()->create(['po_type' => 'HALF BLEACH']);
    $this->routes->assign($lot);
    [$s1, $s2, $s3, $s4, $s5] = $lot->routeSteps->all();
    expect($this->routes->previousPosition($s1)->value())->toBe('ISSUED')
        ->and($this->routes->targetPosition($s1)->value())->toBe("STEP:{$s1->id}")
        ->and($this->routes->targetPosition($s5)->value())->toBe('FINISHED');
    $this->routes->setSkipped($s2, true);
    expect($s2->fresh()->status)->toBe('skipped')
        ->and($this->routes->previousPosition($s3)->value())->toBe("STEP:{$s1->id}");
    $this->routes->setSkipped($s5, true);
    expect($this->routes->targetPosition($s4)->value())->toBe('FINISHED');
    $this->routes->setSkipped($s5, false);
    expect($s5->fresh()->status)->toBe('pending')->and($this->routes->targetPosition($s4)->value())->toBe("STEP:{$s4->id}");
});

it('moves step status pending → partial → done as metres pass through', function () {
    $lot = Lot::factory()->create(['po_type' => 'HALF BLEACH', 'greige_qty' => 100]);
    $this->routes->assign($lot);
    $s1 = $lot->routeSteps->first();
    $this->ledger->post($lot, 'import', null, Position::stock(), 0, 100);
    $this->ledger->post($lot, 'issue', Position::stock(), Position::issued(), 100, 100);
    $this->ledger->post($lot, 'process', Position::issued(), Position::step($s1->id), 40, 40);
    $this->routes->refreshStatuses($lot);
    expect($s1->fresh()->status)->toBe('partial');
    $this->ledger->post($lot, 'process', Position::issued(), Position::step($s1->id), 60, 60);
    $this->routes->refreshStatuses($lot);
    expect($s1->fresh()->status)->toBe('done')->and($this->routes->nextPendingStep($lot)?->id)->toBe($lot->routeSteps[1]->id);
});

it('finds the next pending step per department', function () {
    $lot = Lot::factory()->create(['po_type' => 'HALF BLEACH']);
    $this->routes->assign($lot);
    $steps = $lot->routeSteps;
    $drying = $steps[2]->operation->department_id;
    expect($this->routes->nextPendingStep($lot)?->id)->toBe($steps[0]->id)
        ->and($this->routes->nextPendingStep($lot, $drying)?->id)->toBe($steps[2]->id)
        ->and($this->routes->nextPendingStep($lot, 999999))->toBeNull();
});

it('refuses to skip a step with metres waiting or production recorded', function () {
    $lot = Lot::factory()->create(['po_type' => 'HALF BLEACH', 'greige_qty' => 100]);
    $this->routes->assign($lot);
    [$s1, $s2] = $lot->routeSteps->all();
    $this->ledger->post($lot, 'import', null, Position::stock(), 0, 100);
    $this->ledger->post($lot, 'issue', Position::stock(), Position::issued(), 100, 100);
    $this->ledger->post($lot, 'process', Position::issued(), Position::step($s1->id), 100, 100);
    expect(fn () => $this->routes->setSkipped($s1, true))->toThrow(DomainException::class);
    // s2 itself is untouched, but skipping it... is fine: s1 still lands at STEP:s1 because s3 follows
    $this->routes->setSkipped($s2, true);
    expect($s2->fresh()->status)->toBe('skipped');
});

it('refuses a skip that would move the positions of a step with progress', function () {
    $lot = Lot::factory()->create(['po_type' => 'HALF BLEACH', 'greige_qty' => 100]);
    $this->routes->assign($lot);
    $steps = $lot->routeSteps->all();
    $s4 = $steps[3];
    $s5 = $steps[4];
    $this->ledger->post($lot, 'import', null, Position::stock(), 0, 100);
    $this->ledger->post($lot, 'issue', Position::stock(), Position::issued(), 100, 100);
    // metres processed through s1..s4 sit at STEP:s4, waiting for s5
    $from = Position::issued();
    foreach (array_slice($steps, 0, 4) as $s) {
        $this->ledger->post($lot, 'process', $from, Position::step($s->id), 100, 100);
        $from = Position::step($s->id);
    }
    $this->routes->refreshStatuses($lot);
    expect($s4->fresh()->status)->toBe('done');
    // skipping the last step would make s4's target FINISHED while its metres sit at STEP:s4
    expect(fn () => $this->routes->setSkipped($s5, true))->toThrow(DomainException::class, __('production.errors.route_step_position_locked'))
        ->and($s5->fresh()->status)->toBe('pending');
});

it('replaces the route keeping steps with progress in order and flags added steps', function () {
    $lot = Lot::factory()->create(['po_type' => 'HALF BLEACH', 'greige_qty' => 100]);
    $this->routes->assign($lot);
    $steps = $lot->routeSteps;
    $this->ledger->post($lot, 'import', null, Position::stock(), 0, 100);
    $this->ledger->post($lot, 'issue', Position::stock(), Position::issued(), 100, 100);
    $this->ledger->post($lot, 'process', Position::issued(), Position::step($steps[0]->id), 100, 100);
    $this->routes->refreshStatuses($lot);
    $extra = Operation::factory()->create();

    expect(fn () => $this->routes->replace($lot, [['id' => $steps[1]->id, 'operation_id' => $steps[1]->operation_id]]))
        ->toThrow(DomainException::class, __('production.errors.route_step_in_progress')); // drops the done step
    expect(fn () => $this->routes->replace($lot, [
        ['id' => null, 'operation_id' => $extra->id],
        ['id' => $steps[0]->id, 'operation_id' => $steps[0]->operation_id],
    ]))->toThrow(DomainException::class, __('production.errors.route_step_position_locked')); // the done step would now take from STEP:<extra> instead of ISSUED
    $this->routes->replace($lot, [
        ['id' => $steps[0]->id, 'operation_id' => $steps[0]->operation_id],
        ['id' => null, 'operation_id' => $extra->id],
        ['id' => $steps[2]->id, 'operation_id' => $steps[2]->operation_id],
    ]);
    $now = $lot->routeSteps()->get();
    expect($now->pluck('seq')->all())->toBe([10, 20, 30])
        ->and($now[1]->is_added)->toBeTrue()->and($now[1]->operation_id)->toBe($extra->id)
        ->and($now[0]->id)->toBe($steps[0]->id)->and($now[0]->status)->toBe('done')
        ->and($now[2]->id)->toBe($steps[2]->id)->and($now[2]->is_added)->toBeFalse();
});

it('refuses to reorder steps that already have production', function () {
    $lot = Lot::factory()->create(['po_type' => 'HALF BLEACH', 'greige_qty' => 100]);
    $this->routes->assign($lot);
    $steps = $lot->routeSteps;
    $this->ledger->post($lot, 'import', null, Position::stock(), 0, 100);
    $this->ledger->post($lot, 'issue', Position::stock(), Position::issued(), 100, 100);
    $this->ledger->post($lot, 'process', Position::issued(), Position::step($steps[0]->id), 100, 100);
    $this->ledger->post($lot, 'process', Position::step($steps[0]->id), Position::step($steps[1]->id), 100, 100);
    $this->routes->refreshStatuses($lot);
    expect(fn () => $this->routes->replace($lot, [
        ['id' => $steps[1]->id, 'operation_id' => $steps[1]->operation_id],
        ['id' => $steps[0]->id, 'operation_id' => $steps[0]->operation_id],
        ['id' => $steps[2]->id, 'operation_id' => $steps[2]->operation_id],
    ]))->toThrow(DomainException::class, __('production.errors.route_step_order'));
});

it('keeps is_added on a re-edit that omits it', function () {
    $lot = Lot::factory()->create(['po_type' => 'HALF BLEACH']);
    $this->routes->assign($lot);
    $steps = $lot->routeSteps;
    $extra = Operation::factory()->create();
    $this->routes->replace($lot, [
        ['id' => $steps[0]->id, 'operation_id' => $steps[0]->operation_id],
        ['id' => null, 'operation_id' => $extra->id],
        ['id' => $steps[1]->id, 'operation_id' => $steps[1]->operation_id],
    ]);
    $added = $lot->routeSteps()->get()[1];
    expect($added->is_added)->toBeTrue();
    // a later edit that neither sends is_added nor sends is_added => false for a template step rewrites nothing
    $this->routes->replace($lot, [
        ['id' => $steps[1]->id, 'operation_id' => $steps[1]->operation_id],
        ['id' => $added->id, 'operation_id' => $extra->id],
        ['id' => $steps[0]->id, 'operation_id' => $steps[0]->operation_id, 'is_added' => true],
    ]);
    $now = $lot->routeSteps()->get();
    expect($now->pluck('id')->all())->toBe([$steps[1]->id, $added->id, $steps[0]->id])
        ->and($now->pluck('seq')->all())->toBe([10, 20, 30])
        ->and($now->pluck('is_added')->all())->toBe([false, true, false]);
});

it('changes the operation of a pending step but never of one with production', function () {
    $lot = Lot::factory()->create(['po_type' => 'HALF BLEACH', 'greige_qty' => 100]);
    $this->routes->assign($lot);
    $steps = $lot->routeSteps;
    $this->ledger->post($lot, 'import', null, Position::stock(), 0, 100);
    $this->ledger->post($lot, 'issue', Position::stock(), Position::issued(), 100, 100);
    $this->ledger->post($lot, 'process', Position::issued(), Position::step($steps[0]->id), 100, 100);
    $this->routes->refreshStatuses($lot);
    $other = Operation::factory()->create();
    $payload = fn (int $doneOp, int $pendingOp) => [
        ['id' => $steps[0]->id, 'operation_id' => $doneOp],
        ['id' => $steps[1]->id, 'operation_id' => $pendingOp],
        ['id' => $steps[2]->id, 'operation_id' => $steps[2]->operation_id],
    ];
    expect(fn () => $this->routes->replace($lot, $payload($other->id, $steps[1]->operation_id)))
        ->toThrow(DomainException::class, __('production.errors.route_step_in_progress'))
        ->and($steps[0]->fresh()->operation_id)->toBe($steps[0]->operation_id);
    $this->routes->replace($lot, $payload($steps[0]->operation_id, $other->id));
    $now = $lot->routeSteps()->get();
    expect($now->pluck('id')->all())->toBe([$steps[0]->id, $steps[1]->id, $steps[2]->id])
        ->and($now[0]->operation_id)->toBe($steps[0]->operation_id)->and($now[0]->status)->toBe('done')
        ->and($now[1]->operation_id)->toBe($other->id)->and($now[1]->is_added)->toBeFalse();
});

it('parks kept steps on small temporary seqs whatever their ids', function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Needs MySQL strict mode: SQLite does not range-check an unsigned smallint.');
    }
    $lot = Lot::factory()->create(['po_type' => 'HALF BLEACH']);
    $this->routes->assign($lot);
    $steps = $lot->routeSteps;
    LotRouteStep::whereKey($steps[0]->id)->update(['id' => 60000]); // 10000 + id would overflow seq
    $this->routes->replace($lot, [
        ['id' => 60000, 'operation_id' => $steps[0]->operation_id],
        ['id' => $steps[1]->id, 'operation_id' => $steps[1]->operation_id],
    ]);
    expect($lot->routeSteps()->pluck('seq')->all())->toBe([10, 20])
        ->and($lot->routeSteps()->pluck('id')->all())->toBe([60000, $steps[1]->id]);
});

it('re-derives the status label after a skip, a replace and a re-assign move the next step', function () {
    $lot = Lot::factory()->create(['po_type' => 'HALF BLEACH', 'greige_qty' => 100]);
    $this->routes->assign($lot);
    [$s1, $s2, $s3, $s4, $s5] = $lot->routeSteps->all();
    $this->ledger->post($lot, 'import', null, Position::stock(), 0, 100);
    $this->ledger->post($lot, 'issue', Position::stock(), Position::issued(), 100, 100);
    expect($lot->fresh()->current_label)->toBe('SINGEING/');

    $this->routes->setSkipped($s1, true); // takes the step, so it is $s1->lot that is relabelled, not this instance
    expect($lot->fresh()->current_label)->toBe('BLEACHING/');

    $this->routes->replace($lot, [
        ['id' => $s5->id, 'operation_id' => $s5->operation_id],
        ['id' => $s2->id, 'operation_id' => $s2->operation_id],
        ['id' => $s3->id, 'operation_id' => $s3->operation_id],
        ['id' => $s4->id, 'operation_id' => $s4->operation_id],
    ]);
    expect($lot->fresh()->current_label)->toBe('SANFO/CAL')->and($lot->current_label)->toBe('SANFO/CAL');

    $this->routes->assign($lot, RouteTemplate::where('name', 'Half Bleach')->first(), replace: true);
    expect($lot->fresh()->current_label)->toBe('SINGEING/')->and($lot->current_label)->toBe('SINGEING/');
});

it('applies a template to a PO, skipping lots with progress', function () {
    $a = Lot::factory()->create(['po_type' => 'DYED', 'greige_qty' => 10]);
    $b = Lot::factory()->create(['po_type' => 'DYED', 'contract_id' => $a->contract_id, 'greige_qty' => 10]);
    $this->routes->assign($a);
    $this->routes->assign($b);
    $this->ledger->post($b, 'import', null, Position::stock(), 0, 10);
    $this->ledger->post($b, 'issue', Position::stock(), Position::issued(), 10, 10);
    $this->ledger->post($b, 'process', Position::issued(), Position::step($b->routeSteps->first()->id), 10, 10);
    $this->routes->refreshStatuses($b);
    $t = RouteTemplate::where('name', 'Half Bleach')->first();
    expect($this->routes->applyTemplateToContract($a->contract, $t))->toBe(1)
        ->and($a->routeSteps()->count())->toBe(5)->and($b->routeSteps()->count())->toBe(7);
});
