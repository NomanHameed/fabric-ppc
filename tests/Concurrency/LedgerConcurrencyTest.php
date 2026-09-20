<?php

use App\Models\User;
use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\LotMovement;
use App\Modules\Production\Services\LedgerService;
use Illuminate\Support\Facades\DB;

/*
 * Two real connections against MySQL, with every row committed (this suite uses DatabaseTruncation, not the
 * wrapping transaction of RefreshDatabase, so the second connection can see the test data). Skipped on SQLite.
 *
 * Under InnoDB REPEATABLE READ a plain select inside a transaction reads the snapshot fixed by the transaction's
 * first consistent read. A caller that opens a transaction, reads something, and only then calls the ledger
 * would therefore feed stale figures into the write unless the ledger's reads are locking reads.
 */

beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Needs MySQL: two connections and InnoDB REPEATABLE READ snapshots.');
    }
    $this->default = DB::getDefaultConnection();
    config(['database.connections.mysql2' => config('database.connections.'.$this->default)]);
    $this->user = User::factory()->create();
    $this->lot = Lot::factory()->create(['greige_qty' => 1000]);
    $this->ledger = app(LedgerService::class);

    // the ledger uses the default connection (DB::transaction, the models' resolver), so the other process is
    // simulated by switching the default connection around the call
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
        $this->truncateTablesForAllConnections(); // committed rows must not leak into the transactional suites
    }
});

it('sees an issue committed by another connection after this transaction took its snapshot', function () {
    $this->ledger->post($this->lot, 'import', null, Position::stock(), 0, 1000);

    DB::transaction(function () {
        Lot::query()->whereKey($this->lot->id)->first(); // a plain read: fixes this transaction's snapshot
        expect(LotMovement::query()->where('lot_id', $this->lot->id)->count())->toBe(1);

        ($this->onSecondConnection)(fn () => $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 400, 400));
        expect(LotMovement::query()->where('lot_id', $this->lot->id)->count())->toBe(1); // still the snapshot

        $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 100, 100);
    });

    $lot = $this->lot->fresh();
    expect((float) $lot->issued_qty)->toBe(500.0)->and((float) $lot->stock_qty)->toBe(500.0)->and((float) $lot->wip_qty)->toBe(500.0)
        ->and($this->ledger->balances($lot))->toBe(['STOCK' => 500.0, 'ISSUED' => 500.0]);
    $this->ledger->assertInvariant($lot);
});

it('posts no phantom adjustment on an override when another connection topped the position up', function () {
    $this->ledger->post($this->lot, 'import', null, Position::stock(), 0, 100);

    $result = DB::transaction(function () {
        Lot::query()->whereKey($this->lot->id)->first(); // fixes the snapshot at STOCK = 100
        expect($this->ledger->available($this->lot, Position::stock()))->toBe(100.0);

        ($this->onSecondConnection)(fn () => $this->ledger->post($this->lot, 'adjustment', null, Position::stock(), 0, 500, ['reason' => 'recount']));
        expect($this->ledger->available($this->lot, Position::stock()))->toBe(100.0); // still the snapshot

        return $this->ledger->postWithOverride($this->lot, 'issue', Position::stock(), Position::issued(), 300, 300, 'override', ['userId' => $this->user->id]);
    });

    [$adjustment, $issue] = $result;
    $lot = $this->lot->fresh();
    expect($adjustment)->toBeNull()->and($issue->kind)->toBe('issue')
        ->and(LotMovement::query()->where('lot_id', $lot->id)->orderBy('id')->pluck('kind')->all())->toBe(['import', 'adjustment', 'issue'])
        ->and($this->ledger->balances($lot))->toBe(['STOCK' => 300.0, 'ISSUED' => 300.0])
        ->and((float) $lot->stock_qty)->toBe(300.0)->and((float) $lot->issued_qty)->toBe(300.0);
    $this->ledger->assertInvariant($lot);
});

it('rebuilds from every committed movement, not from this transaction\'s snapshot', function () {
    $this->ledger->post($this->lot, 'import', null, Position::stock(), 0, 1000);

    DB::transaction(function () {
        Lot::query()->whereKey($this->lot->id)->first();
        ($this->onSecondConnection)(fn () => $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 250, 250));
        $this->ledger->rebuild($this->lot);
    });

    $lot = $this->lot->fresh();
    expect($this->ledger->balances($lot))->toBe(['STOCK' => 750.0, 'ISSUED' => 250.0])
        ->and((float) $lot->issued_qty)->toBe(250.0)->and((float) $lot->wip_qty)->toBe(250.0);
    $this->ledger->assertInvariant($lot);
});

it('refuses the second of two concurrent reversals of the same movement', function () {
    $this->ledger->post($this->lot, 'import', null, Position::stock(), 0, 1000);
    $issue = $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 400, 400);

    DB::transaction(function () use ($issue) {
        Lot::query()->whereKey($this->lot->id)->first();
        ($this->onSecondConnection)(fn () => $this->ledger->reverse($issue, $this->user->id, 'first'));
        expect(fn () => $this->ledger->reverse($issue, $this->user->id, 'second'))->toThrow(DomainException::class, 'already been reversed');
    });

    $lot = $this->lot->fresh();
    expect(LotMovement::query()->where('reversal_of_id', $issue->id)->count())->toBe(1)
        ->and($this->ledger->balances($lot))->toBe(['STOCK' => 1000.0, 'ISSUED' => 0.0])
        ->and((float) $lot->issued_qty)->toBe(0.0);
    $this->ledger->assertInvariant($lot);
});
