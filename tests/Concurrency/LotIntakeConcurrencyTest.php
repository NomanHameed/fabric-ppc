<?php

use App\Models\User;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\LotMovement;
use App\Modules\Production\Services\LedgerService;
use App\Modules\Production\Services\LotIntakeService;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RouteTemplateSeeder;
use Illuminate\Support\Facades\DB;

/*
 * Two real connections against MySQL (see LedgerConcurrencyTest for the set-up). An import commit and the
 * backfill command can both receive the same lot; the lot lock taken at the start of receive() serialises them,
 * and the locking reads behind it make the second caller see the first one's rows even when its own transaction
 * fixed its snapshot before the first one committed.
 */

beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Needs MySQL: two connections and InnoDB REPEATABLE READ snapshots.');
    }
    $this->default = DB::getDefaultConnection();
    config(['database.connections.mysql2' => config('database.connections.'.$this->default)]);
    $this->seed([FoundationSeeder::class, RouteTemplateSeeder::class]);
    $this->user = User::factory()->create();
    $this->lot = Lot::factory()->create(['po_type_oracle' => 'DYED', 'greige_received_oracle' => 500]);
    $this->intake = app(LotIntakeService::class);
    $this->ledger = app(LedgerService::class);

    // A model carries the name of the connection it was loaded through (its relations query that connection, whatever
    // the default is at the time), so the other process gets its own instance loaded through the other connection.
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

it('receives a lot once when two connections receive it at the same time', function () {
    $second = null;
    $first = DB::transaction(function () use (&$second) {
        Lot::query()->whereKey($this->lot->id)->first(); // a plain read: fixes this transaction's snapshot before the other one commits
        $second = ($this->onSecondConnection)(fn () => $this->intake->receive(Lot::query()->findOrFail($this->lot->id), $this->user->id));

        return $this->intake->receive($this->lot, $this->user->id);
    });

    $lot = $this->lot->fresh();
    expect($second)->not->toBeNull()->and($first)->toBeNull()
        ->and(LotMovement::query()->where('lot_id', $lot->id)->where('kind', 'import')->count())->toBe(1)
        ->and($lot->routeSteps()->count())->toBe(7)
        ->and($this->ledger->balances($lot))->toBe(['STOCK' => 500.0])
        ->and((float) $lot->greige_in_stock_oracle)->toBe(500.0);
    $this->ledger->assertInvariant($lot);
});
