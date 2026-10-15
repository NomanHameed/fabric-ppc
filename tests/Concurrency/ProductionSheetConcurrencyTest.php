<?php

use App\Models\User;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Machine;
use App\Modules\Foundation\Models\Operation;
use App\Modules\Foundation\Models\Shift;
use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\LotMovement;
use App\Modules\Production\Models\ProductionSheet;
use App\Modules\Production\Services\LedgerService;
use App\Modules\Production\Services\LotRouteService;
use App\Modules\Production\Services\ProductionSheetService;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RouteTemplateSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/*
 * Two real connections against MySQL (see LedgerConcurrencyTest for the set-up). Two sheets can carry lines for
 * the same lot at the same time (two machines, two clerks). A save takes the lot row lock before any plain read,
 * so the second save queues behind the first and, once it runs, sees the first one's rows even when its own
 * transaction fixed its snapshot before the first one committed.
 */

beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Needs MySQL: two connections and InnoDB row locks / REPEATABLE READ snapshots.');
    }
    $this->default = DB::getDefaultConnection();
    config(['database.connections.mysql2' => config('database.connections.'.$this->default)]);
    $this->seed([RolesAndPermissionsSeeder::class, FoundationSeeder::class, RouteTemplateSeeder::class]);
    $this->ppc = User::factory()->create()->assignRole('ppc');
    $this->sheets = app(ProductionSheetService::class);
    $this->ledger = app(LedgerService::class);
    $this->lot = Lot::factory()->create(['po_type_oracle' => 'HALF BLEACH', 'greige_received_oracle' => 1000, 'clr_status_oracle' => 'APPROVED']);
    app(LotRouteService::class)->assign($this->lot);
    $this->ledger->post($this->lot, 'import', null, Position::stock(), 0, 1000);
    $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 1000, 1000);

    $singeing = Department::where('code', 'SINGEING')->firstOrFail();
    $this->header = fn (string $machine, string $date = '2026-09-11') => [
        'sheet_date' => $date, 'shift_id' => Shift::where('code', 'A')->value('id'), 'department_id' => $singeing->id,
        'machine_id' => Machine::where('code', $machine)->value('id'),
    ];
    $this->line = fn (float $qty) => ['lot_id' => $this->lot->id, 'operation_id' => Operation::where('name', 'SINGING')->value('id'), 'qty_in' => $qty, 'qty_out' => $qty];
    // The singeing department has one seeded machine; the second sheet needs another one in the same department.
    $second = Machine::query()->create(['code' => 'SINGEING-2', 'name' => 'Singeing #2']);
    $second->departments()->attach($singeing->id);

    // A model carries the name of the connection it was loaded through, so the other process gets its own
    // instances loaded through the other connection.
    $this->onSecondConnection = function (Closure $fn) {
        DB::setDefaultConnection('mysql2');
        try {
            return $fn();
        } finally {
            DB::setDefaultConnection($this->default);
        }
    };
});

afterEach(function () {
    if (DB::getDriverName() === 'mysql') {
        DB::connection('mysql2')->disconnect();
        $this->truncateTablesForAllConnections();
    }
});

it('waits on the lot row before posting a line', function () {
    DB::transaction(function () {
        Lot::query()->whereKey($this->lot->id)->lockForUpdate()->firstOrFail();
        try {
            ($this->onSecondConnection)(function () {
                DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

                return $this->sheets->save(null, ($this->header)('SINGEING-2'), [($this->line)(100)], $this->ppc);
            });
            $this->fail('The sheet save did not wait on the lot row.');
        } catch (QueryException $e) {
            // the statement that timed out is the lot lock itself: nothing of the save runs before it is held
            expect($e->getMessage())->toContain('Lock wait timeout exceeded')->toContain('for update')->toContain('`lots`');
        }
    });

    expect(ProductionSheet::count())->toBe(0)->and(LotMovement::where('kind', 'process')->count())->toBe(0)
        ->and($this->ledger->balances($this->lot))->toBe(['STOCK' => 0.0, 'ISSUED' => 1000.0]);
    $this->ledger->assertInvariant($this->lot);
});

// Proves the lot row lock, not the snapshot: the save's locking reads see the other connection's committed rows
// even though this transaction's plain reads still see the stale snapshot taken before that commit.
it('sees a sheet committed by another connection after this transaction took its snapshot', function () {
    $result = DB::transaction(function () {
        Lot::query()->whereKey($this->lot->id)->first(); // a plain read: fixes this transaction's snapshot at ISSUED = 1000
        expect($this->ledger->available($this->lot, Position::issued()))->toBe(1000.0);

        ($this->onSecondConnection)(fn () => $this->sheets->save(null, ($this->header)('SINGEING-2'), [($this->line)(400)], $this->ppc));
        expect($this->ledger->available($this->lot, Position::issued()))->toBe(1000.0); // still the snapshot

        // 700 would fit the stale snapshot but not what is really there: the save must refuse it with the true figure
        try {
            $this->sheets->save(null, ($this->header)('SINGEING'), [($this->line)(700)], $this->ppc);
            $this->fail('The save posted against a stale snapshot.');
        } catch (ValidationException $e) {
            expect($e->errors()['lines.0.qty_in'][0])->toContain('600.00');
        }

        return $this->sheets->save(null, ($this->header)('SINGEING'), [($this->line)(600)], $this->ppc);
    });

    $lot = $this->lot->fresh();
    $s1 = $lot->routeSteps()->orderBy('seq')->firstOrFail();
    expect($result->lines->first()->lot_route_step_id)->toBe($s1->id)
        ->and(ProductionSheet::count())->toBe(2)
        ->and(LotMovement::query()->where('lot_id', $lot->id)->orderBy('id')->pluck('kind')->all())->toBe(['import', 'issue', 'process', 'process'])
        ->and($this->ledger->balances($lot))->toBe(['STOCK' => 0.0, 'ISSUED' => 0.0, "STEP:{$s1->id}" => 1000.0])
        ->and((float) $lot->wip)->toBe(1000.0)->and($s1->status)->toBe('done');
    $this->ledger->assertInvariant($lot);
});
