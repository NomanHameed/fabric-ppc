<?php

namespace App\Modules\Production\Jobs;

use App\Modules\Production\Exceptions\LedgerInvariantViolation;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Services\LedgerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/** Nightly: verify every lot's balances and totals against its movements (spec §5.3 "Invariant"); log violations. */
class CheckLedgerInvariant implements ShouldQueue
{
    use Queueable;

    /** @var list<int> lot ids that failed, for tests */
    public array $violations = [];

    public function handle(LedgerService $ledger): void
    {
        Lot::query()->chunkById(200, function ($lots) use ($ledger): void {
            foreach ($lots as $lot) {
                try {
                    $ledger->assertInvariant($lot);
                } catch (LedgerInvariantViolation $e) {
                    $this->violations[] = $lot->id;
                    Log::error('Ledger invariant violated', ['lot_id' => $lot->id, 'lot_no_oracle' => $lot->lot_no_oracle, 'differences' => $e->differences]);
                }
            }
        });
    }
}
