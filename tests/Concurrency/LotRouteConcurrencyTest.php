<?php

use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\LotRouteStep;
use App\Modules\Production\Services\LedgerService;
use App\Modules\Production\Services\LotRouteService;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RouteTemplateSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Two real connections against MySQL (see LedgerConcurrencyTest for the set-up). A route edit takes the lot
 * row lock before anything else, so it queues behind whoever holds that lot (the ledger posting a movement,
 * a state change, another route edit). With the first connection holding the lot row inside a transaction,
 * the second one's edit must wait on it: a one-second lock wait timeout turns that wait into a QueryException,
 * which proves the edit blocked on the lot row rather than proceeding on its own reads.
 */

beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Needs MySQL: two connections and InnoDB row locks.');
    }
    $this->default = DB::getDefaultConnection();
    config(['database.connections.mysql2' => config('database.connections.'.$this->default)]);
    $this->seed([FoundationSeeder::class, RouteTemplateSeeder::class]);
    $this->routes = app(LotRouteService::class);
    $this->ledger = app(LedgerService::class);
    $this->lot = Lot::factory()->create(['po_type_oracle' => 'HALF BLEACH', 'greige_received_oracle' => 100]);
    $this->routes->assign($this->lot);
    $this->ledger->post($this->lot, 'import', null, Position::stock(), 0, 100);

    // A model carries the name of the connection it was loaded through, so the other process gets its own
    // instances loaded through the other connection.
    $this->onSecondConnection = function (Closure $fn) {
        DB::setDefaultConnection('mysql2');
        try {
            DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

            return $fn();
        } finally {
            DB::setDefaultConnection($this->default);
        }
    };
    // The statement that timed out must be the lot row lock itself, not a later read or write of the edit: the
    // lot lock comes first, so nothing of the edit runs before it is held.
    $this->expectLotLockTimeout = function (Closure $edit): void {
        try {
            ($this->onSecondConnection)($edit);
            $this->fail('The route edit did not wait on the lot row.');
        } catch (QueryException $e) {
            expect($e->getMessage())->toContain('Lock wait timeout exceeded')
                ->toMatch('/SQL: select \* from `lots` where `lots`\.`id` = \d+ limit 1 for update\)/');
        }
    };
});

afterEach(function () {
    if (DB::getDriverName() === 'mysql') {
        DB::connection('mysql2')->disconnect();
        $this->truncateTablesForAllConnections();
    }
});

it('waits on the lot row before replacing the route', function () {
    $steps = $this->lot->routeSteps()->get();
    $payload = $steps->map(fn (LotRouteStep $s) => ['id' => $s->id, 'operation_id' => $s->operation_id])->reverse()->values()->all();

    DB::transaction(function () use ($payload) {
        Lot::query()->whereKey($this->lot->id)->lockForUpdate()->firstOrFail();
        ($this->expectLotLockTimeout)(fn () => $this->routes->replace(Lot::query()->findOrFail($this->lot->id), $payload));
    });

    expect($this->lot->routeSteps()->pluck('id')->all())->toBe($steps->pluck('id')->all()); // nothing changed behind the lock
});

it('waits on the lot row before skipping a step', function () {
    $first = $this->lot->routeSteps()->orderBy('seq')->firstOrFail();

    DB::transaction(function () use ($first) {
        Lot::query()->whereKey($this->lot->id)->lockForUpdate()->firstOrFail();
        ($this->expectLotLockTimeout)(fn () => $this->routes->setSkipped(LotRouteStep::query()->findOrFail($first->id), true));
    });

    expect($first->fresh()->status)->toBe('pending');
});
