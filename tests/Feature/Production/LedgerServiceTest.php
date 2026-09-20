<?php

use App\Models\User;
use App\Modules\Production\Exceptions\InsufficientQuantity;
use App\Modules\Production\Exceptions\LedgerInvariantViolation;
use App\Modules\Production\Exceptions\LotNotOpen;
use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\LotBalance;
use App\Modules\Production\Models\LotMovement;
use App\Modules\Production\Services\LedgerService;
use Illuminate\Support\Facades\Date;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->lot = Lot::factory()->create(['greige_qty' => 1000]);
    $this->ledger = app(LedgerService::class);
    $this->import = fn (Lot $lot, float $qty = 1000) => $this->ledger->post($lot, 'import', null, Position::stock(), 0, $qty, ['userId' => $this->user->id]);
});

// ---- spec §5.3 "Ledger rules": import, issue, production line, reprocess, dispatch, loss --------------------

it('posts an import into STOCK and fills the lot totals', function () {
    $m = ($this->import)($this->lot);
    $lot = $this->lot->fresh();
    expect($m->kind)->toBe('import')->and($m->from_position)->toBeNull()->and($m->to_position)->toBe('STOCK')
        ->and($m->qty_out_of_from)->toBe('0.00')->and($m->qty_into_to)->toBe('1000.00')->and($m->user_id)->toBe($this->user->id)
        ->and($this->ledger->balances($lot))->toBe(['STOCK' => 1000.0])
        ->and((float) $lot->stock_qty)->toBe(1000.0)->and((float) $lot->issued_qty)->toBe(0.0)->and((float) $lot->wip_qty)->toBe(0.0)
        ->and((float) $lot->finished_qty)->toBe(0.0)->and((float) $lot->dispatched_qty)->toBe(0.0)->and((float) $lot->loss_qty)->toBe(0.0)
        ->and($lot->last_movement_at)->not->toBeNull()
        ->and($lot->last_movement_at->equalTo($m->moved_at))->toBeTrue();
});

it('keeps the caller\'s lot instance current after a post', function () {
    ($this->import)($this->lot);
    expect($this->lot->stock_qty)->toBe('1000.00')->and($this->lot->isDirty())->toBeFalse();
    $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 250, 250);
    expect($this->lot->stock_qty)->toBe('750.00')->and($this->lot->wip_qty)->toBe('250.00')->and($this->lot->isDirty())->toBeFalse();
});

it('issues part of the stock to the floor', function () {
    ($this->import)($this->lot);
    $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 400, 400);
    $lot = $this->lot->fresh();
    expect($this->ledger->balances($lot))->toBe(['STOCK' => 600.0, 'ISSUED' => 400.0])
        ->and((float) $lot->stock_qty)->toBe(600.0)
        ->and((float) $lot->issued_qty)->toBe(400.0)->and((float) $lot->wip_qty)->toBe(400.0);
});

it('keeps gain and shrink per operation on a process movement', function () {
    ($this->import)($this->lot);
    $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 1000, 1000);
    $this->ledger->post($this->lot, 'process', Position::issued(), Position::step(11), 1000, 1020);
    $lot = $this->lot->fresh();
    expect($this->ledger->balances($lot))->toBe(['STOCK' => 0.0, 'ISSUED' => 0.0, 'STEP:11' => 1020.0])
        ->and((float) $lot->wip_qty)->toBe(1020.0)->and((float) $lot->issued_qty)->toBe(1000.0);
    $this->ledger->post($this->lot, 'process', Position::step(11), Position::finished(), 1020, 990);
    $lot = $this->lot->fresh();
    expect($this->ledger->balances($lot))->toBe(['STOCK' => 0.0, 'ISSUED' => 0.0, 'STEP:11' => 0.0, 'FINISHED' => 990.0])
        ->and((float) $lot->finished_qty)->toBe(990.0)->and((float) $lot->wip_qty)->toBe(0.0);
    $this->ledger->assertInvariant($lot);
});

it('records reprocess in place and dispatch/loss as terminal counters', function () {
    ($this->import)($this->lot);
    $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 1000, 1000);
    $this->ledger->post($this->lot, 'process', Position::issued(), Position::finished(), 1000, 1000);
    $re = $this->ledger->post($this->lot, 'reprocess', Position::finished(), Position::finished(), 300, 295);
    expect($re->from_position)->toBe('FINISHED')->and($re->to_position)->toBe('FINISHED')
        ->and($this->ledger->balances($this->lot)['FINISHED'])->toBe(995.0);
    $this->ledger->post($this->lot, 'dispatch', Position::finished(), Position::dispatched(), 900, 900);
    $this->ledger->post($this->lot, 'loss', Position::finished(), Position::loss(), 95, 95, ['reason' => 'CUT_PIECES']);
    $lot = $this->lot->fresh();
    expect($this->ledger->balances($lot)['FINISHED'])->toBe(0.0)
        ->and((float) $lot->dispatched_qty)->toBe(900.0)->and((float) $lot->loss_qty)->toBe(95.0)
        ->and((float) $lot->finished_qty)->toBe(0.0)->and((float) $lot->wip_qty)->toBe(0.0);
    $this->ledger->assertInvariant($lot);
});

it('never lets metres leave a terminal position except by reversal', function () {
    ($this->import)($this->lot);
    $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 1000, 1000);
    $this->ledger->post($this->lot, 'process', Position::issued(), Position::finished(), 1000, 1000);
    $dispatch = $this->ledger->post($this->lot, 'dispatch', Position::finished(), Position::dispatched(), 100, 100);
    expect(fn () => $this->ledger->post($this->lot, 'adjustment', Position::dispatched(), Position::finished(), 100, 100))
        ->toThrow(InvalidArgumentException::class);
    $rev = $this->ledger->reverse($dispatch, $this->user->id, 'wrong lot');
    expect($rev->from_position)->toBe('DISPATCHED')->and($rev->to_position)->toBe('FINISHED')
        ->and($this->ledger->balances($this->lot))->toBe(['STOCK' => 0.0, 'ISSUED' => 0.0, 'FINISHED' => 1000.0, 'DISPATCHED' => 0.0])
        ->and((float) $this->lot->fresh()->dispatched_qty)->toBe(0.0);
});

// ---- spec §5.3 "Validation": no overdraw, override posts an adjustment first ---------------------------------

it('rejects taking more than a position holds, reporting what is available', function () {
    ($this->import)($this->lot);
    try {
        $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 1000.01, 1000.01);
        $this->fail('expected InsufficientQuantity');
    } catch (InsufficientQuantity $e) {
        expect($e->available)->toBe(1000.0)->and($e->requested)->toBe(1000.01)
            ->and($e->position->value())->toBe('STOCK')->and($e->lot->id)->toBe($this->lot->id)
            ->and($e->getMessage())->toContain('1,000.00')->and($e->getMessage())->toContain('Greige in stock');
    }
    expect(LotMovement::count())->toBe(1)->and($this->ledger->balances($this->lot))->toBe(['STOCK' => 1000.0])
        ->and((float) $this->lot->fresh()->stock_qty)->toBe(1000.0);
});

it('treats a position the lot never had as holding nothing', function () {
    ($this->import)($this->lot);
    expect($this->ledger->available($this->lot, Position::finished()))->toBe(0.0)
        ->and(fn () => $this->ledger->post($this->lot, 'dispatch', Position::finished(), Position::dispatched(), 0.01, 0.01))
        ->toThrow(InsufficientQuantity::class);
});

it('posts an adjustment first on an override, so the ledger never goes negative', function () {
    ($this->import)($this->lot, 100);
    [$adj, $issue] = $this->ledger->postWithOverride($this->lot, 'issue', Position::stock(), Position::issued(), 150, 150, 'Oracle qty short', ['userId' => $this->user->id]);
    expect($adj->kind)->toBe('adjustment')->and((float) $adj->qty_into_to)->toBe(50.0)->and($adj->reason)->toBe('Oracle qty short')
        ->and($adj->from_position)->toBeNull()->and($adj->to_position)->toBe('STOCK')->and($adj->user_id)->toBe($this->user->id)
        ->and($issue->kind)->toBe('issue')->and($issue->reason)->toBe('Oracle qty short')->and($issue->id)->toBe($adj->id + 1)
        ->and($this->ledger->balances($this->lot))->toBe(['STOCK' => 0.0, 'ISSUED' => 150.0]);
    $this->ledger->assertInvariant($this->lot);
});

it('posts no adjustment on an override when the position holds enough', function () {
    ($this->import)($this->lot);
    [$adj, $issue] = $this->ledger->postWithOverride($this->lot, 'issue', Position::stock(), Position::issued(), 1000, 1000, 'not needed');
    expect($adj)->toBeNull()->and($issue->kind)->toBe('issue')->and(LotMovement::count())->toBe(2);
});

it('rolls back an override completely when the movement itself is rejected', function () {
    ($this->import)($this->lot, 100);
    $this->lot->update(['state' => 'hold']);
    expect(fn () => $this->ledger->postWithOverride($this->lot, 'issue', Position::stock(), Position::issued(), 150, 150, 'r'))
        ->toThrow(LotNotOpen::class);
    expect(LotMovement::count())->toBe(1)->and($this->ledger->balances($this->lot))->toBe(['STOCK' => 100.0]);
});

// ---- spec §5.3 "Edits and deletes": reversals ---------------------------------------------------------------

it('reverses a movement with a new row and restores the balances', function () {
    ($this->import)($this->lot);
    $issue = $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 400, 400);
    $rev = $this->ledger->reverse($issue, $this->user->id, 'typo');
    expect($rev->reversal_of_id)->toBe($issue->id)->and($rev->kind)->toBe('issue')->and($rev->user_id)->toBe($this->user->id)
        ->and($rev->reason)->toBe('typo')->and($rev->from_position)->toBe('ISSUED')->and($rev->to_position)->toBe('STOCK')
        ->and($rev->reversalOf->is($issue))->toBeTrue()
        ->and($this->ledger->balances($this->lot))->toBe(['STOCK' => 1000.0, 'ISSUED' => 0.0])
        ->and((float) $this->lot->fresh()->issued_qty)->toBe(0.0)
        ->and(LotMovement::count())->toBe(3)
        ->and($issue->fresh()->getAttributes())->toEqual($issue->getAttributes()); // the original row is untouched
    $this->ledger->assertInvariant($this->lot);
});

it('refuses a reversal when the metres have already moved on', function () {
    ($this->import)($this->lot);
    $issue = $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 400, 400);
    $this->ledger->post($this->lot, 'process', Position::issued(), Position::finished(), 400, 400);
    expect(fn () => $this->ledger->reverse($issue, $this->user->id))->toThrow(InsufficientQuantity::class);
});

it('reverses a movement only once', function () {
    ($this->import)($this->lot);
    $issue = $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 400, 400);
    $this->ledger->reverse($issue, $this->user->id);
    expect(fn () => $this->ledger->reverse($issue, $this->user->id))->toThrow(InvalidArgumentException::class)
        ->and(LotMovement::count())->toBe(3);
});

it('keeps the original source on a reversal even when the source model is gone', function () {
    $source = User::factory()->create();
    $m = $this->ledger->post($this->lot, 'import', null, Position::stock(), 0, 10, ['source' => $source]);
    $source->delete();
    $rev = $this->ledger->reverse($m->fresh(), $this->user->id);
    expect($rev->source_type)->toBe($source->getMorphClass())->and($rev->source_id)->toBe($source->id)
        ->and($this->ledger->balances($this->lot))->toBe(['STOCK' => 0.0]);
});

// ---- lot state, model guards -------------------------------------------------------------------------------

it('rejects floor movements on a lot that is not open', function () {
    ($this->import)($this->lot);
    $this->lot->update(['state' => 'hold']);
    expect(fn () => $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 10, 10))->toThrow(LotNotOpen::class);
    try {
        $this->ledger->post($this->lot, 'process', Position::stock(), Position::finished(), 10, 10);
        $this->fail('expected LotNotOpen');
    } catch (LotNotOpen $e) {
        expect($e->getMessage())->toContain('On hold');
    }
    // an adjustment is still allowed (corrections), as is a loss on closing
    $this->ledger->post($this->lot, 'adjustment', null, Position::stock(), 0, 5);
    $this->ledger->post($this->lot, 'loss', Position::stock(), Position::loss(), 5, 5, ['reason' => 'DAMAGED']);
    expect($this->ledger->balances($this->lot))->toBe(['STOCK' => 1000.0, 'LOSS' => 5.0]);
});

it('rejects malformed movements before touching the database', function (string $kind, ?Position $from, ?Position $to, float $out, float $in) {
    ($this->import)($this->lot);
    expect(fn () => $this->ledger->post($this->lot, $kind, $from, $to, $out, $in))->toThrow(InvalidArgumentException::class)
        ->and(LotMovement::count())->toBe(1);
})->with([
    'unknown kind' => ['transfer', Position::stock(), Position::issued(), 1, 1],
    'no positions' => ['adjustment', null, null, 1, 1],
    'negative out' => ['issue', Position::stock(), Position::issued(), -1, 1],
    'negative in' => ['issue', Position::stock(), Position::issued(), 1, -1],
    'nothing moves' => ['issue', Position::stock(), Position::issued(), 0, 0],
    'one-sided zero' => ['adjustment', null, Position::stock(), 0, 0],
    'nothing leaves the from position' => ['process', Position::stock(), Position::finished(), 0, 10],
]);

it('refuses to update or delete a movement row through the model', function () {
    $m = ($this->import)($this->lot);
    expect(fn () => $m->update(['qty_into_to' => 5]))->toThrow(LogicException::class)
        ->and(fn () => $m->delete())->toThrow(LogicException::class)
        ->and($m->fresh()->qty_into_to)->toBe('1000.00');
});

// ---- precision, timestamps, source, auditing ---------------------------------------------------------------

it('adds and subtracts two-decimal quantities without float drift', function () {
    ($this->import)($this->lot, 0.3);
    $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 0.1, 0.1);
    $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 0.2, 0.2);
    expect($this->ledger->balances($this->lot))->toBe(['STOCK' => 0.0, 'ISSUED' => 0.3]);
    expect(fn () => $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 0.01, 0.01))->toThrow(InsufficientQuantity::class);
    $this->ledger->post($this->lot, 'issue', Position::issued(), Position::issued(), 0.3, 0.3); // exactly what is there is fine
    $this->ledger->assertInvariant($this->lot);
    expect($this->lot->fresh()->wip_qty)->toBe('0.30');
});

it('rounds quantities to two decimals at the boundary', function () {
    $m = ($this->import)($this->lot, 10.005);
    expect($m->qty_into_to)->toBe('10.01')->and($this->ledger->balances($this->lot))->toBe(['STOCK' => 10.01]);
});

it('stores moved_at as given and keeps last_movement_at at the latest movement', function () {
    $early = Date::parse('2026-09-01 10:00:00');
    $late = Date::parse('2026-09-15 10:00:00');
    $this->ledger->post($this->lot, 'import', null, Position::stock(), 0, 1000, ['movedAt' => $early]);
    $m1 = $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 10, 10, ['movedAt' => $late]);
    $m2 = $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 10, 10, ['movedAt' => $early]);
    expect($m1->fresh()->moved_at->equalTo($late))->toBeTrue()->and($m2->fresh()->moved_at->equalTo($early))->toBeTrue()
        ->and($this->lot->fresh()->last_movement_at->equalTo($late))->toBeTrue();
});

it('records the source model on a movement', function () {
    $source = User::factory()->create(); // any model works as a polymorphic source in this test
    $m = $this->ledger->post($this->lot, 'import', null, Position::stock(), 0, 10, ['source' => $source]);
    expect($m->source_type)->toBe($source->getMorphClass())->and($m->source_id)->toBe($source->id)
        ->and($m->fresh()->source->is($source))->toBeTrue();
});

it('records the acting user when none is given', function () {
    $this->actingAs($this->user);
    $m = $this->ledger->post($this->lot, 'import', null, Position::stock(), 0, 10);
    expect($m->user_id)->toBe($this->user->id)->and($m->user->is($this->user))->toBeTrue();
});

it('does not audit the derived total columns', function () {
    config(['audit.console' => true]);
    ($this->import)($this->lot);
    $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 10, 10);
    expect($this->lot->fresh()->audits()->where('event', 'updated')->count())->toBe(0);
});

it('exposes movements and balances on the lot', function () {
    ($this->import)($this->lot);
    $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 10, 10, ['movedAt' => Date::parse('2026-01-01')]);
    $lot = $this->lot->fresh();
    expect($lot->movements()->pluck('kind')->all())->toBe(['issue', 'import'])
        ->and($lot->balances()->pluck('qty', 'position')->all())->toBe(['STOCK' => '990.00', 'ISSUED' => '10.00']);
});

// ---- spec §5.3 "Invariant": detection and repair ------------------------------------------------------------

it('detects a tampered balance and rebuilds from the movements', function () {
    ($this->import)($this->lot);
    $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 100, 100);
    LotBalance::where('lot_id', $this->lot->id)->where('position', 'STOCK')->update(['qty' => 999]);
    Lot::whereKey($this->lot->id)->update(['stock_qty' => 1, 'wip_qty' => 1]);
    try {
        $this->ledger->assertInvariant($this->lot);
        $this->fail('expected LedgerInvariantViolation');
    } catch (LedgerInvariantViolation $e) {
        expect($e->differences)->toBe(['STOCK' => ['balance' => 999.0, 'expected' => 900.0]])
            ->and($e->getMessage())->toContain('STOCK');
    }
    $this->ledger->rebuild($this->lot);
    $this->ledger->assertInvariant($this->lot);
    $lot = $this->lot->fresh();
    expect($this->ledger->balances($lot))->toBe(['STOCK' => 900.0, 'ISSUED' => 100.0])
        ->and((float) $lot->stock_qty)->toBe(900.0)->and((float) $lot->wip_qty)->toBe(100.0)->and((float) $lot->issued_qty)->toBe(100.0)
        ->and($lot->last_movement_at)->not->toBeNull()
        ->and($this->lot->stock_qty)->toBe('900.00');
});

it('detects a balance row with no movements behind it', function () {
    ($this->import)($this->lot);
    LotBalance::create(['lot_id' => $this->lot->id, 'position' => 'FINISHED', 'qty' => 5]);
    expect(fn () => $this->ledger->assertInvariant($this->lot))->toThrow(LedgerInvariantViolation::class);
    $this->ledger->rebuild($this->lot);
    expect($this->ledger->balances($this->lot))->toBe(['STOCK' => 1000.0]);
});

it('holds the whole-lot sum identity across every kind of movement', function () {
    ($this->import)($this->lot);
    $this->ledger->post($this->lot, 'adjustment', null, Position::stock(), 0, 20);
    $this->ledger->post($this->lot, 'issue', Position::stock(), Position::issued(), 1020, 1020);
    $this->ledger->post($this->lot, 'process', Position::issued(), Position::step(1), 1020, 1050);
    $this->ledger->post($this->lot, 'process', Position::step(1), Position::finished(), 1050, 1000);
    $this->ledger->post($this->lot, 'reprocess', Position::finished(), Position::finished(), 100, 98);
    $this->ledger->post($this->lot, 'dispatch', Position::finished(), Position::dispatched(), 500, 500);
    $this->ledger->post($this->lot, 'loss', Position::finished(), Position::loss(), 8, 8);
    $lot = $this->lot->fresh();
    $sumOfPositions = array_sum($this->ledger->balances($lot));
    $netOfMovements = (float) LotMovement::where('lot_id', $lot->id)->sum('qty_into_to') - (float) LotMovement::where('lot_id', $lot->id)->sum('qty_out_of_from');
    // greige + adjustments + Σ(out − in) over process/reprocess lines
    $expected = 1000 + 20 + (1050 - 1020) + (1000 - 1050) + (98 - 100);
    expect($sumOfPositions)->toBe($netOfMovements)->and($sumOfPositions)->toBe((float) $expected)
        ->and((float) $lot->stock_qty + (float) $lot->wip_qty + (float) $lot->finished_qty + (float) $lot->dispatched_qty + (float) $lot->loss_qty)->toBe((float) $expected);
    $this->ledger->assertInvariant($lot);
});
