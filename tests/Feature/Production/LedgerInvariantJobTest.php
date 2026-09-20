<?php

use App\Modules\Production\Jobs\CheckLedgerInvariant;
use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\LotBalance;
use App\Modules\Production\Services\LedgerService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

it('logs the lots whose balances disagree with their movements', function () {
    $ledger = app(LedgerService::class);
    $ok = Lot::factory()->create();
    $bad = Lot::factory()->create();
    Lot::factory()->create(); // never moved: skipped
    $ledger->post($ok, 'import', null, Position::stock(), 0, 10);
    $ledger->post($bad, 'import', null, Position::stock(), 0, 10);
    LotBalance::where('lot_id', $bad->id)->update(['qty' => 1]);
    Log::spy();

    $job = new CheckLedgerInvariant;
    $job->handle($ledger);

    expect($job->violations)->toBe([$bad->id]);
    Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context) => $context['lot_id'] === $bad->id
        && $context['lot_no'] === $bad->lot_no
        && $context['differences'] === ['STOCK' => ['balance' => 1.0, 'expected' => 10.0]]);
});

it('is scheduled daily', function () {
    $events = collect(Schedule::events())->filter(fn ($e) => str_contains($e->description ?? '', CheckLedgerInvariant::class));
    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('0 2 * * *');
});
