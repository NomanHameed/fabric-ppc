<?php

use App\Models\User;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Machine;
use App\Modules\Foundation\Models\Operation;
use App\Modules\Foundation\Models\Shift;
use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\ColourOverride;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\LotMovement;
use App\Modules\Production\Models\ProductionLine;
use App\Modules\Production\Models\ProductionSheet;
use App\Modules\Production\Services\BatchSplitter;
use App\Modules\Production\Services\LedgerService;
use App\Modules\Production\Services\LotRouteService;
use App\Modules\Production\Services\ProductionSheetService;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RouteTemplateSeeder;
use Illuminate\Validation\ValidationException;

/*
 * ProductionSheetService against the real ledger and routes (spec §5.3 "Production line on route step N",
 * "Reprocess line", "Unplanned operation", "Validation", "Edits and deletes"; §5.4 gate; §6.3 totals).
 * Every scenario ends with the ledger invariant.
 */

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, FoundationSeeder::class, RouteTemplateSeeder::class]);
    $this->ppc = User::factory()->create()->assignRole('ppc');
    $this->ledger = app(LedgerService::class);
    $this->routes = app(LotRouteService::class);
    $this->sheets = app(ProductionSheetService::class);
    // HALF BLEACH route: SINGING (SINGEING) → SINGLE BLEACH (JIGGER_DYEING) → DRY (DRYING) → WHITE FINISH FOR SANFORIZING (FINISH_STENTER) → SANFORIZE (SANFO_CALENDER)
    $this->lot = Lot::factory()->create(['po_type' => 'HALF BLEACH', 'greige_qty' => 1000, 'colour_status' => 'APPROVED', 'g_width' => 63, 'f_width' => 58]);
    $this->routes->assign($this->lot);
    $this->ledger->post($this->lot, 'import', null, Position::stock(), 0, 1000);
    $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 1000, 1000);
    $this->dept = fn (string $code) => Department::where('code', $code)->firstOrFail();
    $this->machine = fn (string $code) => Machine::where('code', $code)->firstOrFail();
    $this->op = fn (string $dept, string $name) => Operation::where('name', $name)->whereHas('department', fn ($q) => $q->where('code', $dept))->firstOrFail();
    $this->step = fn (string $dept, string $name) => $this->lot->routeSteps()->where('operation_id', ($this->op)($dept, $name)->id)->firstOrFail();
    $this->header = fn (string $dept, string $machine, string $date = '2026-09-11') => [
        'sheet_date' => $date, 'shift_id' => Shift::where('code', 'A')->value('id'), 'department_id' => ($this->dept)($dept)->id, 'machine_id' => ($this->machine)($machine)->id,
    ];
    $this->line = fn (array $o = []) => $o + ['lot_id' => $this->lot->id, 'operation_id' => ($this->op)('SINGEING', 'SINGING')->id, 'qty_in' => 1000, 'qty_out' => 995];
    $this->errors = function (Closure $fn): array {
        try {
            $fn();
        } catch (ValidationException $e) {
            return $e->errors();
        }
        $this->fail('expected a ValidationException');
    };
});

afterEach(function () {
    foreach (Lot::all() as $lot) {
        $this->ledger->assertInvariant($lot);
    }
});

// ---- posting on the route --------------------------------------------------------------------------------------

it('posts a line on the next pending step of the sheet department and advances the route', function () {
    $sheet = $this->sheets->save(null, ($this->header)('SINGEING', 'SINGEING'), [($this->line)()], $this->ppc);
    $s1 = $this->lot->routeSteps->first();
    $line = $sheet->lines->first();
    $m = $line->movements()->first();
    expect($line->lot_route_step_id)->toBe($s1->id)->and($line->line_no)->toBe(1)->and($line->is_reprocess)->toBeFalse()
        ->and((float) $line->g_width)->toBe(63.0)->and((float) $line->f_width)->toBe(58.0) // the lot's widths by default
        ->and($this->ledger->balances($this->lot))->toBe(['STOCK' => 0.0, 'ISSUED' => 0.0, "STEP:{$s1->id}" => 995.0])
        ->and($s1->fresh()->status)->toBe('done')
        ->and($m->kind)->toBe('process')->and($m->from_position)->toBe('ISSUED')->and($m->to_position)->toBe("STEP:{$s1->id}")
        ->and((float) $m->qty_out_of_from)->toBe(1000.0)->and((float) $m->qty_into_to)->toBe(995.0)
        ->and($m->user_id)->toBe($this->ppc->id)->and($m->source_id)->toBe($line->id)->and($m->source_type)->toBe($line->getMorphClass())
        ->and($m->moved_at->format('d-m-Y H:i'))->toBe('11-09-2026 12:00')
        ->and($this->lot->fresh()->current_label)->toBe('BLEACHING/')
        ->and($sheet->entered_by)->toBe($this->ppc->id);
});

it('moves the step status partial → done as the metres go through, and back to pending when reversed', function () {
    $s1 = $this->lot->routeSteps->first();
    $first = $this->sheets->save(null, ($this->header)('SINGEING', 'SINGEING'), [($this->line)(['qty_in' => 400, 'qty_out' => 398])], $this->ppc);
    expect($s1->fresh()->status)->toBe('partial')->and($this->ledger->balances($this->lot)['ISSUED'])->toBe(600.0);

    $second = $this->sheets->save(null, ($this->header)('SINGEING', 'SINGEING', '2026-09-12'), [($this->line)(['qty_in' => 600, 'qty_out' => 600])], $this->ppc);
    expect($s1->fresh()->status)->toBe('done')->and($this->ledger->balances($this->lot)["STEP:{$s1->id}"])->toBe(998.0)
        ->and((float) $this->lot->fresh()->wip_qty)->toBe(998.0);

    $this->sheets->delete($second, $this->ppc);
    expect($s1->fresh()->status)->toBe('partial');
    $this->sheets->delete($first, $this->ppc);
    expect($s1->fresh()->status)->toBe('pending')->and($this->ledger->balances($this->lot))->toBe(['STOCK' => 0.0, 'ISSUED' => 1000.0, "STEP:{$s1->id}" => 0.0]);
});

it('walks a lot to FINISHED through every department, posting each line from previousPosition to targetPosition', function () {
    $walk = [
        ['SINGEING', 'SINGING', 'SINGEING'], ['JIGGER_DYEING', 'SINGLE BLEACH', 'JIGGER'], ['DRYING', 'DRY', 'DRYING-1'],
        ['FINISH_STENTER', 'WHITE FINISH FOR SANFORIZING', 'STENTER-1'], ['SANFO_CALENDER', 'SANFORIZE', 'SANFO'],
    ];
    $qty = 1000;
    foreach ($walk as $i => [$d, $o, $m]) {
        $sheet = $this->sheets->save(null, ($this->header)($d, $m, '2026-09-0'.($i + 1)), [($this->line)(['operation_id' => ($this->op)($d, $o)->id, 'qty_in' => $qty, 'qty_out' => $qty - 1])], $this->ppc);
        $qty--;
        $line = $sheet->lines->first();
        $step = ($this->step)($d, $o);
        expect($line->lot_route_step_id)->toBe($step->id)->and($step->fresh()->status)->toBe('done')
            ->and($line->movements()->first()->from_position)->toBe($this->routes->previousPosition($step)->value())
            ->and($line->movements()->first()->to_position)->toBe($this->routes->targetPosition($step)->value());
    }
    expect($this->ledger->balances($this->lot)['FINISHED'])->toBe(995.0)->and((float) $this->lot->fresh()->finished_qty)->toBe(995.0)
        ->and((float) $this->lot->fresh()->wip_qty)->toBe(0.0)->and($this->lot->fresh()->current_label)->toBe('RF-INSP/');
});

it('takes an explicit route step and validates it against the lot', function () {
    $bleach = ($this->step)('JIGGER_DYEING', 'SINGLE BLEACH');
    // the explicit step wins over the department default, and the ledger reports what is at its source
    $errors = ($this->errors)(fn () => $this->sheets->save(null, ($this->header)('JIGGER_DYEING', 'JIGGER'),
        [($this->line)(['operation_id' => ($this->op)('JIGGER_DYEING', 'SINGLE BLEACH')->id, 'lot_route_step_id' => $bleach->id, 'qty_in' => 10, 'qty_out' => 10])], $this->ppc));
    expect($errors)->toHaveKey('lines.0.qty_in')->and($errors['lines.0.qty_in'][0])->toContain('0.00');

    $other = Lot::factory()->create(['po_type' => 'HALF BLEACH']);
    $this->routes->assign($other);
    $foreign = $other->routeSteps->first();
    $errors = ($this->errors)(fn () => $this->sheets->save(null, ($this->header)('SINGEING', 'SINGEING'), [($this->line)(['lot_route_step_id' => $foreign->id])], $this->ppc));
    expect($errors)->toHaveKey('lines.0.lot_route_step_id');

    $s1 = $this->lot->routeSteps->first();
    $this->routes->setSkipped($s1, true);
    $errors = ($this->errors)(fn () => $this->sheets->save(null, ($this->header)('SINGEING', 'SINGEING'), [($this->line)(['lot_route_step_id' => $s1->id])], $this->ppc));
    expect($errors)->toHaveKey('lines.0.lot_route_step_id')->and(ProductionSheet::count())->toBe(0);
});

it('restricts an explicit step to the sheet department, for planned and reprocess lines alike', function () {
    $bleach = ($this->step)('JIGGER_DYEING', 'SINGLE BLEACH');
    $incharge = User::factory()->create()->assignRole('dept_incharge');
    $incharge->departments()->attach(($this->dept)('SINGEING')->id);
    foreach ([$this->ppc, $incharge] as $user) {
        // a singeing sheet naming the bleach step: the singeing operation may not stand in for another department's step
        $errors = ($this->errors)(fn () => $this->sheets->save(null, ($this->header)('SINGEING', 'SINGEING'), [($this->line)(['lot_route_step_id' => $bleach->id])], $user));
        expect($errors)->toHaveKey('lines.0.lot_route_step_id')->and($errors['lines.0.lot_route_step_id'][0])->toBe(__('production.errors.step_not_in_department'));
        $errors = ($this->errors)(fn () => $this->sheets->save(null, ($this->header)('SINGEING', 'SINGEING'),
            [($this->line)(['lot_route_step_id' => $bleach->id, 'is_reprocess' => true, 'reprocess_reason' => 'OTHER', 'qty_in' => 10, 'qty_out' => 10])], $user));
        expect($errors)->toHaveKey('lines.0.lot_route_step_id');
    }
    expect(ProductionSheet::count())->toBe(0)->and(LotMovement::where('lot_id', $this->lot->id)->whereIn('kind', ['process', 'reprocess'])->count())->toBe(0)
        ->and($this->ledger->balances($this->lot)['ISSUED'])->toBe(1000.0);
});

it('rejects an operation from another department and a machine outside the department', function () {
    $errors = ($this->errors)(fn () => $this->sheets->save(null, ($this->header)('SINGEING', 'SINGEING'), [($this->line)(['operation_id' => ($this->op)('DRYING', 'DRY')->id])], $this->ppc));
    expect($errors)->toHaveKey('lines.0.operation_id');
    $errors = ($this->errors)(fn () => $this->sheets->save(null, ($this->header)('SINGEING', 'JIGGER'), [($this->line)()], $this->ppc));
    expect($errors)->toHaveKey('machine_id')->and(ProductionSheet::count())->toBe(0)->and($this->ledger->balances($this->lot)['ISSUED'])->toBe(1000.0);
});

it('refuses a line on a held lot as a lot error', function () {
    $this->lot->update(['state' => 'hold']);
    $errors = ($this->errors)(fn () => $this->sheets->save(null, ($this->header)('SINGEING', 'SINGEING'), [($this->line)()], $this->ppc));
    expect($errors)->toHaveKey('lines.0.lot_id')->and(ProductionSheet::count())->toBe(0);
});

// ---- validation and override (spec §5.3 "Validation") --------------------------------------------------------

it('reports the available metres and honours a production.override with reason', function () {
    $errors = ($this->errors)(fn () => $this->sheets->save(null, ($this->header)('SINGEING', 'SINGEING'), [($this->line)(['qty_in' => 1200, 'qty_out' => 1200])], $this->ppc));
    expect($errors)->toHaveKey('lines.0.qty_in')->and($errors['lines.0.qty_in'][0])->toContain('1,000.00')->toContain('1,200.00');
    expect(ProductionSheet::count())->toBe(0)->and(ProductionLine::count())->toBe(0); // the whole save rolled back

    $sheet = $this->sheets->save(null, ($this->header)('SINGEING', 'SINGEING'), [($this->line)(['qty_in' => 1200, 'qty_out' => 1200, 'override_reason' => 'Oracle short by 200'])], $this->ppc);
    $movements = $sheet->lines->first()->movements()->get();
    expect($movements->pluck('kind')->all())->toBe(['adjustment', 'process'])
        ->and($movements->first()->to_position)->toBe('ISSUED')->and((float) $movements->first()->qty_into_to)->toBe(200.0)->and($movements->first()->reason)->toBe('Oracle short by 200')
        ->and($this->ledger->balances($this->lot)['STEP:'.$this->lot->routeSteps->first()->id])->toBe(1200.0)
        ->and((float) $this->lot->fresh()->wip_qty)->toBe(1200.0);
});

it('refuses a quantity override from a user without production.override', function () {
    $incharge = User::factory()->create()->assignRole('dept_incharge');
    $incharge->departments()->attach(($this->dept)('SINGEING')->id);
    $errors = ($this->errors)(fn () => $this->sheets->save(null, ($this->header)('SINGEING', 'SINGEING'), [($this->line)(['qty_in' => 1200, 'qty_out' => 1200, 'override_reason' => 'x'])], $incharge));
    expect($errors)->toHaveKey('lines.0.override_reason')->and(LotMovement::where('kind', 'adjustment')->count())->toBe(0);
    // and without a reason the plain refusal (with the available figure) applies to them as well
    $errors = ($this->errors)(fn () => $this->sheets->save(null, ($this->header)('SINGEING', 'SINGEING'), [($this->line)(['qty_in' => 1200, 'qty_out' => 1200])], $incharge));
    expect($errors)->toHaveKey('lines.0.qty_in');
});

// ---- reprocess and unplanned (spec §5.3) ----------------------------------------------------------------------

it('keeps reprocess metres in place with a reason, and unplanned work on the largest floor position', function () {
    $this->sheets->save(null, ($this->header)('SINGEING', 'SINGEING'), [($this->line)()], $this->ppc);
    $s1 = $this->lot->routeSteps->first();
    // reprocess the singeing step
    $sheet = $this->sheets->save(null, ($this->header)('SINGEING', 'SINGEING', '2026-09-12'),
        [($this->line)(['lot_route_step_id' => $s1->id, 'is_reprocess' => true, 'reprocess_reason' => 'WHITENESS', 'qty_in' => 995, 'qty_out' => 990])], $this->ppc);
    $line = $sheet->lines->first();
    $m = $line->movements()->first();
    expect($line->is_reprocess)->toBeTrue()->and($line->reprocess_reason)->toBe('WHITENESS')->and($line->lot_route_step_id)->toBe($s1->id)
        ->and($m->kind)->toBe('reprocess')->and($m->from_position)->toBe("STEP:{$s1->id}")->and($m->to_position)->toBe("STEP:{$s1->id}")->and($m->reason)->toBe('WHITENESS')
        ->and($this->ledger->balances($this->lot)["STEP:{$s1->id}"])->toBe(990.0)->and($s1->fresh()->status)->toBe('done');
    // an unplanned drying operation (not on the route at this point) records in place too
    $this->lot->routeSteps()->where('operation_id', ($this->op)('DRYING', 'DRY')->id)->delete();
    $sheet = $this->sheets->save(null, ($this->header)('DRYING', 'DRYING-1', '2026-09-13'),
        [($this->line)(['operation_id' => ($this->op)('DRYING', 'DRY')->id, 'qty_in' => 990, 'qty_out' => 985])], $this->ppc);
    $line = $sheet->lines->first();
    $m = $line->movements()->first();
    expect($line->lot_route_step_id)->toBeNull()->and($line->is_reprocess)->toBeFalse()
        ->and($m->kind)->toBe('process')->and($m->from_position)->toBe("STEP:{$s1->id}")->and($m->to_position)->toBe("STEP:{$s1->id}")
        ->and($this->ledger->balances($this->lot)["STEP:{$s1->id}"])->toBe(985.0)
        ->and($this->lot->routeSteps()->where('id', $s1->id)->value('status'))->toBe('done'); // in-place work does not disturb the statuses
});

it('needs a known reprocess reason, and treats a reprocess-kind operation as reprocess', function () {
    $this->sheets->save(null, ($this->header)('SINGEING', 'SINGEING'), [($this->line)()], $this->ppc);
    $this->sheets->save(null, ($this->header)('JIGGER_DYEING', 'JIGGER', '2026-09-12'), [($this->line)(['operation_id' => ($this->op)('JIGGER_DYEING', 'SINGLE BLEACH')->id, 'qty_in' => 995, 'qty_out' => 995])], $this->ppc);
    $this->sheets->save(null, ($this->header)('DRYING', 'DRYING-1', '2026-09-13'), [($this->line)(['operation_id' => ($this->op)('DRYING', 'DRY')->id, 'qty_in' => 995, 'qty_out' => 995])], $this->ppc);
    $dry = ($this->step)('DRYING', 'DRY');
    $redry = ($this->op)('DRYING', 'RE DRY'); // kind = reprocess

    $errors = ($this->errors)(fn () => $this->sheets->save(null, ($this->header)('DRYING', 'DRYING-2', '2026-09-14'), [($this->line)(['operation_id' => $redry->id, 'qty_in' => 995, 'qty_out' => 995])], $this->ppc));
    expect($errors)->toHaveKey('lines.0.reprocess_reason');
    $errors = ($this->errors)(fn () => $this->sheets->save(null, ($this->header)('DRYING', 'DRYING-2', '2026-09-14'), [($this->line)(['operation_id' => $redry->id, 'reprocess_reason' => 'NOPE', 'qty_in' => 995, 'qty_out' => 995])], $this->ppc));
    expect($errors)->toHaveKey('lines.0.reprocess_reason');

    // no step named: the department's step holding the metres is the one recorded, and the metres stay there
    $sheet = $this->sheets->save(null, ($this->header)('DRYING', 'DRYING-2', '2026-09-14'), [($this->line)(['operation_id' => $redry->id, 'reprocess_reason' => 'OTHER', 'qty_in' => 995, 'qty_out' => 993])], $this->ppc);
    $line = $sheet->lines->first();
    expect($line->is_reprocess)->toBeTrue()->and($line->lot_route_step_id)->toBe($dry->id)
        ->and($line->movements()->first()->kind)->toBe('reprocess')->and($line->movements()->first()->from_position)->toBe("STEP:{$dry->id}")
        ->and($this->ledger->balances($this->lot)["STEP:{$dry->id}"])->toBe(993.0);
});

it('refuses an unplanned line when nothing is on the floor', function () {
    $stock = Lot::factory()->create(['po_type' => 'HALF BLEACH', 'greige_qty' => 100, 'colour_status' => 'APPROVED']);
    $this->ledger->post($stock, 'import', null, Position::stock(), 0, 100); // no route, nothing issued
    $errors = ($this->errors)(fn () => $this->sheets->save(null, ($this->header)('SINGEING', 'SINGEING'), [($this->line)(['lot_id' => $stock->id, 'qty_in' => 10, 'qty_out' => 10])], $this->ppc));
    expect($errors)->toHaveKey('lines.0.qty_in');
});

// ---- edits and deletes (spec §5.3 "Edits and deletes") --------------------------------------------------------

it('edits and deletes lines through reversals, never by touching movements', function () {
    $sheet = $this->sheets->save(null, ($this->header)('SINGEING', 'SINGEING'), [($this->line)()], $this->ppc);
    $line = $sheet->lines->first();
    $original = $line->movements()->first();

    $this->sheets->save($sheet, ($this->header)('SINGEING', 'SINGEING'), [['id' => $line->id] + ($this->line)(['qty_in' => 1000, 'qty_out' => 980])], $this->ppc);
    $s1 = $this->lot->routeSteps->first();
    $movements = LotMovement::where('lot_id', $this->lot->id)->where('kind', 'process')->orderBy('id')->get();
    expect($movements->count())->toBe(3) // post, reversal, re-post
        ->and($movements[0]->id)->toBe($original->id)->and((float) $movements[0]->qty_into_to)->toBe(995.0) // untouched
        ->and($movements[1]->reversal_of_id)->toBe($original->id)->and($movements[1]->from_position)->toBe("STEP:{$s1->id}")->and($movements[1]->to_position)->toBe('ISSUED')
        ->and($movements[1]->reason)->toBe(__('production.reasons.line_edited'))
        ->and($movements[2]->reversal_of_id)->toBeNull()->and((float) $movements[2]->qty_into_to)->toBe(980.0)
        ->and($movements->pluck('source_id')->unique()->all())->toBe([$line->id])
        ->and($this->ledger->balances($this->lot)["STEP:{$s1->id}"])->toBe(980.0)
        ->and((float) $line->fresh()->qty_out)->toBe(980.0)->and(ProductionLine::count())->toBe(1);

    $this->sheets->delete($sheet->fresh(), $this->ppc);
    expect($this->ledger->balances($this->lot))->toBe(['STOCK' => 0.0, 'ISSUED' => 1000.0, "STEP:{$s1->id}" => 0.0])
        ->and($s1->fresh()->status)->toBe('pending')->and(ProductionSheet::count())->toBe(0)->and(ProductionLine::count())->toBe(0)
        ->and(LotMovement::where('lot_id', $this->lot->id)->where('kind', 'process')->count())->toBe(4) // the delete added one more reversal, nothing was removed
        ->and((float) $this->lot->fresh()->wip_qty)->toBe(1000.0);
});

it('re-posts every line on the new date when the sheet date changes', function () {
    $sheet = $this->sheets->save(null, ($this->header)('SINGEING', 'SINGEING'), [($this->line)()], $this->ppc);
    $line = $sheet->lines->first();
    $s1 = $this->lot->routeSteps->first();
    $before = $this->ledger->balances($this->lot);

    $this->sheets->save($sheet, ($this->header)('SINGEING', 'SINGEING', '2026-09-13'), [['id' => $line->id] + ($this->line)()], $this->ppc);
    $movements = LotMovement::where('lot_id', $this->lot->id)->where('kind', 'process')->orderBy('id')->get();
    expect($movements->count())->toBe(3) // post, reversal, re-post: the movement date is the sheet date
        ->and($movements[1]->reversal_of_id)->toBe($movements[0]->id)->and($movements[2]->reversal_of_id)->toBeNull()
        ->and($movements[2]->moved_at->format('d-m-Y H:i'))->toBe('13-09-2026 12:00')
        ->and($sheet->fresh()->sheet_date->toDateString())->toBe('2026-09-13')
        ->and($this->ledger->balances($this->lot))->toBe($before)->and($s1->fresh()->status)->toBe('done');
    // the same date again: the unchanged line is left alone
    $this->sheets->save($sheet->fresh(), ($this->header)('SINGEING', 'SINGEING', '2026-09-13'), [['id' => $line->id] + ($this->line)()], $this->ppc);
    expect(LotMovement::where('lot_id', $this->lot->id)->where('kind', 'process')->count())->toBe(3);
});

it('re-posts the later lines of the sheet on the same lot when an earlier line changes', function () {
    $s1 = $this->lot->routeSteps->first();
    $sheet = $this->sheets->save(null, ($this->header)('SINGEING', 'SINGEING'), [
        ($this->line)(),
        ($this->line)(['lot_route_step_id' => $s1->id, 'is_reprocess' => true, 'reprocess_reason' => 'OTHER', 'qty_in' => 500, 'qty_out' => 498]),
    ], $this->ppc);
    [$a, $b] = $sheet->lines->all();
    expect($this->ledger->balances($this->lot)["STEP:{$s1->id}"])->toBe(993.0);

    // line 2 reprocessed line 1's output: editing line 1 alone could not reverse it; line 2 is unposted first and re-posted after
    $this->sheets->save($sheet, ($this->header)('SINGEING', 'SINGEING'), [
        ['id' => $a->id] + ($this->line)(['qty_out' => 990]),
        ['id' => $b->id] + ($this->line)(['lot_route_step_id' => $s1->id, 'is_reprocess' => true, 'reprocess_reason' => 'OTHER', 'qty_in' => 500, 'qty_out' => 498]),
    ], $this->ppc);
    expect(LotMovement::where('source_id', $a->id)->where('kind', 'process')->count())->toBe(3)
        ->and(LotMovement::where('source_id', $b->id)->where('kind', 'reprocess')->count())->toBe(3)
        ->and(LotMovement::where('lot_id', $this->lot->id)->whereIn('kind', ['process', 'reprocess'])->orderBy('id')->pluck('kind')->all())
        ->toBe(['process', 'reprocess', 'reprocess', 'process', 'process', 'reprocess']) // unposted last line first, re-posted in sheet order
        ->and($this->ledger->balances($this->lot)["STEP:{$s1->id}"])->toBe(988.0)
        ->and((float) $a->fresh()->qty_out)->toBe(990.0)->and((float) $b->fresh()->qty_out)->toBe(498.0)->and($s1->fresh()->status)->toBe('done');
});

it('updates batch no, remarks, widths and output type in place, removes dropped lines and renumbers', function () {
    $sheet = $this->sheets->save(null, ($this->header)('SINGEING', 'SINGEING'), [
        ($this->line)(['batch_no' => 'B1', 'qty_in' => 400, 'qty_out' => 400]),
        ($this->line)(['batch_no' => 'B1', 'qty_in' => 300, 'qty_out' => 300]),
        ($this->line)(['qty_in' => 300, 'qty_out' => 300]),
    ], $this->ppc);
    [$a, $b, $c] = $sheet->lines->all();
    $before = LotMovement::count();

    $sheet = $this->sheets->save($sheet, ($this->header)('SINGEING', 'SINGEING'), [
        ['id' => $c->id] + ($this->line)(['qty_in' => 300, 'qty_out' => 300, 'remarks' => 'moved up', 'g_width' => 64.5, 'output_type' => 'dyed']),
        ['id' => $a->id] + ($this->line)(['batch_no' => 'B2', 'qty_in' => 400, 'qty_out' => 400]),
    ], $this->ppc);
    $lines = $sheet->lines;
    expect($lines->pluck('id')->all())->toBe([$c->id, $a->id])->and($lines->pluck('line_no')->all())->toBe([1, 2])
        ->and($lines[0]->remarks)->toBe('moved up')->and((float) $lines[0]->g_width)->toBe(64.5)->and($lines[0]->output_type)->toBe('dyed')
        ->and($lines[1]->batch_no)->toBe('B2')
        ->and(LotMovement::count())->toBe($before + 3) // b's reversal; c, later on the same lot, is reversed and re-posted after it; a is untouched
        ->and(LotMovement::where('source_id', $b->id)->latest('id')->first()->reversal_of_id)->not->toBeNull()
        ->and(LotMovement::where('source_id', $a->id)->count())->toBe(1)
        ->and(LotMovement::where('source_id', $c->id)->count())->toBe(3)
        ->and($this->ledger->balances($this->lot))->toBe(['STOCK' => 0.0, 'ISSUED' => 300.0, 'STEP:'.$this->lot->routeSteps->first()->id => 700.0])
        ->and($this->lot->routeSteps->first()->fresh()->status)->toBe('partial');
});

it('refuses to change or delete a line whose metres have already moved on, until the later work is undone', function () {
    $first = $this->sheets->save(null, ($this->header)('SINGEING', 'SINGEING'), [($this->line)()], $this->ppc);
    $second = $this->sheets->save(null, ($this->header)('JIGGER_DYEING', 'JIGGER', '2026-09-12'),
        [($this->line)(['operation_id' => ($this->op)('JIGGER_DYEING', 'SINGLE BLEACH')->id, 'qty_in' => 995, 'qty_out' => 990])], $this->ppc);
    // bleaching took the singeing output: the singeing line's movement can no longer be reversed
    $line = $first->lines->first();
    $errors = ($this->errors)(fn () => $this->sheets->save($first, ($this->header)('SINGEING', 'SINGEING'), [['id' => $line->id] + ($this->line)(['qty_in' => 1000, 'qty_out' => 500])], $this->ppc));
    expect($errors)->toHaveKey('lines.0.qty_in')->and($errors['lines.0.qty_in'][0])->toContain($this->lot->lot_no);
    $errors = ($this->errors)(fn () => $this->sheets->delete($first->fresh(), $this->ppc));
    expect($errors)->toHaveKey('lines')->and(ProductionSheet::count())->toBe(2);
    // undo the bleaching first, then the singeing line can change
    $this->sheets->delete($second->fresh(), $this->ppc);
    $this->sheets->save($first, ($this->header)('SINGEING', 'SINGEING'), [['id' => $line->id] + ($this->line)(['qty_in' => 1000, 'qty_out' => 999])], $this->ppc);
    expect($this->ledger->balances($this->lot)['STEP:'.$this->lot->routeSteps->first()->id])->toBe(999.0);
});

it('reverses an override adjustment together with its movement', function () {
    $sheet = $this->sheets->save(null, ($this->header)('SINGEING', 'SINGEING'), [($this->line)(['qty_in' => 1200, 'qty_out' => 1200, 'override_reason' => 'short'])], $this->ppc);
    $this->sheets->delete($sheet, $this->ppc);
    expect(LotMovement::where('lot_id', $this->lot->id)->orderBy('id')->pluck('kind')->all())->toBe(['import', 'issue', 'adjustment', 'process', 'process', 'adjustment'])
        ->and($this->ledger->balances($this->lot))->toBe(['STOCK' => 0.0, 'ISSUED' => 1000.0, 'STEP:'.$this->lot->routeSteps->first()->id => 0.0]);
});

it('rejects a line id that is not on the sheet', function () {
    $sheet = $this->sheets->save(null, ($this->header)('SINGEING', 'SINGEING'), [($this->line)(['qty_in' => 100, 'qty_out' => 100])], $this->ppc);
    $errors = ($this->errors)(fn () => $this->sheets->save($sheet, ($this->header)('SINGEING', 'SINGEING'), [['id' => 999999] + ($this->line)(['qty_in' => 100, 'qty_out' => 100])], $this->ppc));
    expect($errors)->toHaveKey('lines.0.id');
});

// ---- colour gate (spec §5.4) -----------------------------------------------------------------------------------

it('gates dyeing on an unapproved colour and logs the override', function () {
    $this->lot->update(['colour_status' => 'DIP']);
    $this->sheets->save(null, ($this->header)('SINGEING', 'SINGEING'), [($this->line)()], $this->ppc); // singeing is not gated
    $bleach = ($this->line)(['operation_id' => ($this->op)('JIGGER_DYEING', 'SINGLE BLEACH')->id, 'qty_in' => 995, 'qty_out' => 990]);

    $errors = ($this->errors)(fn () => $this->sheets->save(null, ($this->header)('JIGGER_DYEING', 'JIGGER', '2026-09-12'), [$bleach], $this->ppc));
    expect($errors)->toHaveKey('lines.0.colour_override_reason')->and($errors['lines.0.colour_override_reason'][0])->toContain($this->lot->lot_no)->toContain('DIP')->toContain('SINGLE BLEACH')
        ->and(ProductionSheet::count())->toBe(1)->and(LotMovement::where('kind', 'process')->count())->toBe(1);

    $sheet = $this->sheets->save(null, ($this->header)('JIGGER_DYEING', 'JIGGER', '2026-09-12'), [array_merge($bleach, ['colour_override_reason' => 'Party accepted lab dip verbally'])], $this->ppc);
    $o = ColourOverride::first();
    expect(ColourOverride::count())->toBe(1)->and($o->production_line_id)->toBe($sheet->lines->first()->id)->and($o->lot_id)->toBe($this->lot->id)
        ->and($o->approved_by)->toBe($this->ppc->id)->and($o->reason)->toContain('lab dip')->and($o->approved_at)->not->toBeNull()
        ->and($sheet->lines->first()->colourOverride->id)->toBe($o->id);

    // the override stands through an edit of the line without a new reason
    $line = $sheet->lines->first();
    $this->sheets->save($sheet, ($this->header)('JIGGER_DYEING', 'JIGGER', '2026-09-12'), [['id' => $line->id] + array_merge($bleach, ['qty_out' => 985])], $this->ppc);
    expect(ColourOverride::count())->toBe(1)->and((float) $line->fresh()->qty_out)->toBe(985.0);
    // and goes with the line
    $this->sheets->delete($sheet->fresh(), $this->ppc);
    expect(ColourOverride::count())->toBe(0);
});

it('keeps a logged override through edits: the same reason from anyone, a new reason only from an approver', function () {
    $this->lot->update(['colour_status' => 'DIP']);
    $this->sheets->save(null, ($this->header)('SINGEING', 'SINGEING'), [($this->line)()], $this->ppc);
    $bleach = ($this->line)(['operation_id' => ($this->op)('JIGGER_DYEING', 'SINGLE BLEACH')->id, 'qty_in' => 995, 'qty_out' => 990, 'colour_override_reason' => 'Verbal OK']);
    $sheet = $this->sheets->save(null, ($this->header)('JIGGER_DYEING', 'JIGGER', '2026-09-12'), [$bleach], $this->ppc);
    $line = $sheet->lines->first();
    $override = ColourOverride::firstOrFail();
    $stamp = $override->approved_at;
    $manager = User::factory()->create()->assignRole('manager');
    $incharge = User::factory()->create()->assignRole('dept_incharge');
    $incharge->departments()->attach(($this->dept)('JIGGER_DYEING')->id);

    // a manager re-saving the same reason is not a new decision: the PPC user's stamp stands
    $this->travel(1)->hours();
    $this->sheets->save($sheet, ($this->header)('JIGGER_DYEING', 'JIGGER', '2026-09-12'), [['id' => $line->id] + array_merge($bleach, ['qty_out' => 985])], $manager);
    expect(ColourOverride::count())->toBe(1)->and($override->fresh()->approved_by)->toBe($this->ppc->id)->and($override->fresh()->approved_at->equalTo($stamp))->toBeTrue();
    // and neither is the in-charge (who could not approve one) re-submitting the screen with the reason still on the line
    $this->sheets->save($sheet->fresh(), ($this->header)('JIGGER_DYEING', 'JIGGER', '2026-09-12'), [['id' => $line->id] + array_merge($bleach, ['qty_out' => 980])], $incharge);
    expect((float) $line->fresh()->qty_out)->toBe(980.0)->and($override->fresh()->approved_by)->toBe($this->ppc->id);
    // a different reason is a new decision: refused from the in-charge, re-stamped by an approver
    $errors = ($this->errors)(fn () => $this->sheets->save($sheet->fresh(), ($this->header)('JIGGER_DYEING', 'JIGGER', '2026-09-12'),
        [['id' => $line->id] + array_merge($bleach, ['colour_override_reason' => 'Party signed off'])], $incharge));
    expect($errors)->toHaveKey('lines.0.colour_override_reason')->and($override->fresh()->reason)->toBe('Verbal OK');
    $this->sheets->save($sheet->fresh(), ($this->header)('JIGGER_DYEING', 'JIGGER', '2026-09-12'), [['id' => $line->id] + array_merge($bleach, ['colour_override_reason' => 'Party signed off'])], $manager);
    expect($override->fresh()->reason)->toBe('Party signed off')->and($override->fresh()->approved_by)->toBe($manager->id)->and($override->fresh()->approved_at->greaterThan($stamp))->toBeTrue()
        ->and(ColourOverride::count())->toBe(1);

    // the colour approved after the fact: a re-post keeps the logged decision; moving the line to another lot drops it
    $this->lot->update(['colour_status' => 'APPROVED']);
    $this->sheets->save($sheet->fresh(), ($this->header)('JIGGER_DYEING', 'JIGGER', '2026-09-12'), [['id' => $line->id] + array_merge($bleach, ['qty_out' => 975])], $this->ppc);
    expect(ColourOverride::count())->toBe(1)->and($override->fresh()->production_line_id)->toBe($line->id)->and((float) $line->fresh()->qty_out)->toBe(975.0);
    $other = Lot::factory()->create(['po_type' => 'HALF BLEACH', 'greige_qty' => 100, 'colour_status' => 'APPROVED']);
    $this->routes->assign($other);
    $this->ledger->post($other, 'import', null, Position::stock(), 0, 100);
    $this->ledger->post($other, 'issue', Position::stock(), Position::issued(), 100, 100);
    $this->sheets->save(null, ($this->header)('SINGEING', 'SINGEING', '2026-09-13'), [($this->line)(['lot_id' => $other->id, 'qty_in' => 100, 'qty_out' => 100])], $this->ppc);
    $this->sheets->save($sheet->fresh(), ($this->header)('JIGGER_DYEING', 'JIGGER', '2026-09-12'), [['id' => $line->id] + array_merge($bleach, ['lot_id' => $other->id, 'qty_in' => 100, 'qty_out' => 100])], $this->ppc);
    expect(ColourOverride::count())->toBe(0)->and($line->fresh()->lot_id)->toBe($other->id);
});

it('refuses a colour override from a user without colour.override.approve', function () {
    $this->lot->update(['colour_status' => 'DIP']);
    $this->sheets->save(null, ($this->header)('SINGEING', 'SINGEING'), [($this->line)()], $this->ppc);
    $bleach = ($this->line)(['operation_id' => ($this->op)('JIGGER_DYEING', 'SINGLE BLEACH')->id, 'qty_in' => 1, 'qty_out' => 1]);
    $incharge = User::factory()->create()->assignRole('dept_incharge');
    $incharge->departments()->attach(($this->dept)('JIGGER_DYEING')->id);

    $errors = ($this->errors)(fn () => $this->sheets->save(null, ($this->header)('JIGGER_DYEING', 'JIGGER', '2026-09-13'), [array_merge($bleach, ['colour_override_reason' => 'x'])], $incharge));
    expect($errors)->toHaveKey('lines.0.colour_override_reason')->and(ColourOverride::count())->toBe(0)->and(ProductionSheet::count())->toBe(1);
});

it('gates a finishing operation by finish_type and passes every approved status', function () {
    $finish = ($this->op)('FINISH_STENTER', 'WHITE FINISH FOR SANFORIZING'); // finish_type = dyed, department not in the gated list
    foreach ([['SINGEING', 'SINGING', 'SINGEING'], ['JIGGER_DYEING', 'SINGLE BLEACH', 'JIGGER'], ['DRYING', 'DRY', 'DRYING-1']] as $i => [$d, $o, $m]) {
        $this->sheets->save(null, ($this->header)($d, $m, '2026-09-0'.($i + 1)), [($this->line)(['operation_id' => ($this->op)($d, $o)->id, 'qty_in' => 1000, 'qty_out' => 1000])], $this->ppc);
    }
    $finishLine = ($this->line)(['operation_id' => $finish->id, 'qty_in' => 100, 'qty_out' => 100]);
    foreach (['DIP', 'REJECTED', null] as $status) {
        $this->lot->update(['colour_status' => $status]);
        $errors = ($this->errors)(fn () => $this->sheets->save(null, ($this->header)('FINISH_STENTER', 'STENTER-1'), [$finishLine], $this->ppc));
        expect($errors)->toHaveKey('lines.0.colour_override_reason');
    }
    foreach (['APPROVED', 'MWS_APP', 'DIRECT_PROD'] as $i => $status) {
        $this->lot->update(['colour_status' => $status]);
        $this->sheets->save(null, ($this->header)('FINISH_STENTER', 'STENTER-1', '2026-09-1'.$i), [$finishLine], $this->ppc);
    }
    expect(ColourOverride::count())->toBe(0)->and(ProductionSheet::count())->toBe(6);
});

// ---- totals (spec §6.3.6) --------------------------------------------------------------------------------------

it('computes the sheet totals: fresh, reprocess, stretching, by output type', function () {
    $finish = ($this->op)('FINISH_STENTER', 'WHITE FINISH FOR SANFORIZING');
    $stretch = ($this->op)('FINISH_STENTER', 'STRETCH FOR FINISH');
    expect($stretch->is_stretch)->toBeTrue()->and($stretch->finish_type)->toBeNull()->and($finish->finish_type)->toBe('dyed');
    // walk the lot to the finish stenter
    foreach ([['SINGEING', 'SINGING', 'SINGEING'], ['JIGGER_DYEING', 'SINGLE BLEACH', 'JIGGER'], ['DRYING', 'DRY', 'DRYING-1']] as $i => [$d, $o, $m]) {
        $this->sheets->save(null, ($this->header)($d, $m, '2026-09-0'.($i + 1)), [($this->line)(['operation_id' => ($this->op)($d, $o)->id, 'qty_in' => 1000, 'qty_out' => 1000])], $this->ppc);
    }
    $finishStep = ($this->step)('FINISH_STENTER', 'WHITE FINISH FOR SANFORIZING');
    $sheet = $this->sheets->save(null, ($this->header)('FINISH_STENTER', 'STENTER-1'), [
        ($this->line)(['operation_id' => $finish->id, 'qty_in' => 600, 'qty_out' => 610]),
        ($this->line)(['operation_id' => $finish->id, 'lot_route_step_id' => $finishStep->id, 'is_reprocess' => true, 'reprocess_reason' => 'WIDTH', 'qty_in' => 100, 'qty_out' => 100]),
        ($this->line)(['operation_id' => $stretch->id, 'lot_route_step_id' => $finishStep->id, 'is_reprocess' => false, 'qty_in' => 50, 'qty_out' => 52]),
        ($this->line)(['operation_id' => $finish->id, 'qty_in' => 100, 'qty_out' => 99, 'output_type' => 'print']),
    ], $this->ppc);
    $t = $this->sheets->totals($sheet);
    expect($t['all'])->toBe(['fresh' => 709.0, 'reprocess' => 100.0, 'stretching' => 52.0, 'total' => 861.0])
        ->and($t['dyed'])->toBe(['fresh' => 610.0, 'reprocess' => 100.0, 'stretching' => 0.0, 'total' => 710.0])
        ->and($t['print'])->toBe(['fresh' => 99.0, 'reprocess' => 0.0, 'stretching' => 0.0, 'total' => 99.0])
        ->and($t['other'])->toBe(['fresh' => 0.0, 'reprocess' => 0.0, 'stretching' => 52.0, 'total' => 52.0])
        ->and($sheet->lines->pluck('output_type')->all())->toBe(['dyed', 'dyed', null, 'print'])
        ->and($this->ledger->balances($this->lot)["STEP:{$finishStep->id}"])->toBe(761.0) // 610 + 52 + 99; the reprocess stays in place
        ->and($this->ledger->balances($this->lot)['STEP:'.($this->step)('DRYING', 'DRY')->id])->toBe(250.0)
        ->and($finishStep->fresh()->status)->toBe('partial');
});

// ---- batch split ---------------------------------------------------------------------------------------------

it('accepts a split batch: lines with one batch no, proportional to what is available', function () {
    $other = Lot::factory()->create(['po_type' => 'HALF BLEACH', 'greige_qty' => 500, 'colour_status' => 'APPROVED']);
    $this->routes->assign($other);
    $this->ledger->post($other, 'import', null, Position::stock(), 0, 500);
    $this->ledger->post($other, 'issue', Position::stock(), Position::issued(), 500, 500);
    $split = BatchSplitter::split(1200, [0 => 1000, 1 => 500]);
    expect($split)->toBe([0 => 800.0, 1 => 400.0]);

    $sheet = $this->sheets->save(null, ($this->header)('SINGEING', 'SINGEING'), [
        ($this->line)(['batch_no' => 'BATCH-7', 'qty_in' => $split[0], 'qty_out' => $split[0]]),
        ($this->line)(['batch_no' => 'BATCH-7', 'lot_id' => $other->id, 'qty_in' => $split[1], 'qty_out' => $split[1]]),
    ], $this->ppc);
    expect($sheet->lines->pluck('batch_no')->unique()->all())->toBe(['BATCH-7'])
        ->and($this->ledger->balances($this->lot)['ISSUED'])->toBe(200.0)->and($this->ledger->balances($other)['ISSUED'])->toBe(100.0)
        ->and($this->lot->routeSteps->first()->fresh()->status)->toBe('partial')->and($other->routeSteps->first()->fresh()->status)->toBe('partial');
});

// ---- route lock ------------------------------------------------------------------------------------------------

it('cannot remove a route step that has production lines', function () {
    $this->sheets->save(null, ($this->header)('SINGEING', 'SINGEING'), [($this->line)()], $this->ppc);
    $steps = $this->lot->routeSteps;
    $this->actingAs($this->ppc)->put("/production/routes/{$this->lot->id}", ['steps' => $steps->slice(1)->map(fn ($s) => ['id' => $s->id, 'operation_id' => $s->operation_id])->values()->all()])
        ->assertSessionHasErrors('steps');
    expect($this->lot->routeSteps()->count())->toBe(5);
});
