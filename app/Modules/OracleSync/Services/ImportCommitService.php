<?php

namespace App\Modules\OracleSync\Services;

use App\Models\User;
use App\Modules\Foundation\Models\Quality;
use App\Modules\OracleSync\Data\OracleLotRow;
use App\Modules\OracleSync\Events\LotChanged;
use App\Modules\OracleSync\Events\LotImported;
use App\Modules\OracleSync\Models\OracleImport;
use App\Modules\Production\Models\Contract;
use App\Modules\Production\Models\Lot;
use Closure;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ImportCommitService
{
    public function __construct(private readonly PartyResolver $parties) {}

    /**
     * Run $callback with model auditing switched on and $user as the audit user, then restore both.
     *
     * Queue workers and artisan commands count as the console, where `audit.console` is false and nobody is
     * logged in, so without this an import commit would leave no audit trail (final review I-6). Lots and
     * contracts get per-model audits; the bulk `oracle_missing_at` flag changes are traced by the
     * `oracle_import_rows` rows with action `missing` instead.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function asAuditUser(User $user, Closure $callback): mixed
    {
        $console = config('audit.console');
        $previous = Auth::user();
        config(['audit.console' => true]);
        Auth::setUser($user);

        try {
            return $callback();
        } finally {
            config(['audit.console' => $console]);
            if ($previous !== null) {
                Auth::setUser($previous);
            } else {
                Auth::forgetUser();
            }
        }
    }

    /**
     * The checks the commit screen runs synchronously before queueing, and the job repeats before writing.
     *
     * @throws DomainException
     */
    public function assertCommittable(OracleImport $import): void
    {
        if (! in_array($import->status, ['previewed', 'committing'], true)) {
            throw new DomainException(__('oracle.errors.not_previewed'));
        }
        if ($import->error_count > 0) {
            throw new DomainException(__('oracle.errors.has_errors'));
        }
        $stale = OracleImport::query()->where('status', 'committed')->where('committed_at', '>', $import->created_at)->exists();
        if ($stale) {
            throw new DomainException(__('oracle.errors.stale'));
        }
    }

    /** Atomically move a previewed import to committing; false when it was not previewed (already claimed). */
    public function claim(OracleImport $import): bool
    {
        return OracleImport::query()->whereKey($import->id)->where('status', 'previewed')->update(['status' => 'committing']) === 1;
    }

    /**
     * Commit a previewed import (CLI path, claimed here) or one the commit screen already moved to committing.
     */
    public function commit(OracleImport $import, User $user): void
    {
        // One commit at a time, whichever preview it comes from: the stale check is not atomic across imports (M-7).
        // The TTL matches the job timeout margin (REDIS_QUEUE_RETRY_AFTER >= 660, see config/queue.php).
        $lock = Cache::lock('oracle-import-commit', 660);
        if (! $lock->get()) {
            throw new DomainException(__('oracle.errors.commit_in_progress'));
        }

        try {
            $events = $this->commitLocked($import);
        } finally {
            $lock->release();
        }

        // Events fire after the lock is released.
        foreach ($events as $event) {
            event($event);
        }
    }

    /**
     * The body of commit(), run while the commit lock is held.
     *
     * @return list<object> the LotImported/LotChanged events to fire once the lock is released
     */
    private function commitLocked(OracleImport $import): array
    {
        $import->refresh();
        $claimed = $import->status === 'committing';

        try {
            $this->assertCommittable($import);
        } catch (DomainException $e) {
            if ($claimed) {
                $import->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            }
            throw $e;
        }

        if (! $claimed && ! $this->claim($import)) {
            throw new DomainException(__('oracle.errors.not_previewed'));
        }
        $import->status = 'committing';
        $events = [];

        try {
            DB::transaction(function () use ($import, &$events) {
                $qualities = [];
                $contracts = [];

                foreach ($import->rows()->whereIn('action', ['new', 'changed'])->orderBy('id')->cursor() as $r) {
                    $row = OracleLotRow::fromArray($r->payload);
                    $party = $this->parties->resolve($row->partyCode, $row->partyName)
                        ?? throw new DomainException(__('oracle.errors.unknown_party', ['code' => $row->partyCode, 'name' => $row->partyName]));
                    if ($party->oracle_code === null) {
                        // Name-matched party: remember its Oracle code so later imports compare party_code correctly.
                        $party->update(['oracle_code' => $row->partyCode]);
                    }
                    $quality = $qualities[$row->qualityCode] ??= Quality::fromOracle($row->qualityCode, $row->qualityDescription);
                    $contract = $contracts[$row->poNo] ??= Contract::query()->updateOrCreate(['po_no' => $row->poNo], [
                        'party_id' => $party->id, 'ref_no' => $row->refNo, 'po_date' => $row->poDate->toDateString(),
                        'contract_qty' => $row->contractQty, 'oracle_missing_at' => null,
                    ]);
                    $attrs = [
                        'lot_no' => $row->lotNo, 'contract_id' => $contract->id, 'quality_id' => $quality->id, 'gsm' => $row->gsm,
                        'design_code' => $row->designCode, 'design' => $row->design, 'colour_code' => $row->colourCode,
                        'colour' => $row->colour, 'g_width' => $row->gWidth, 'f_width' => $row->fWidth, 'po_type' => $row->poType,
                        'greige_qty' => $row->greigeQty, 'oracle_missing_at' => null,
                    ];

                    if ($r->action === 'new') {
                        $lot = Lot::query()->create($attrs + ['oracle_line_id' => $row->oracleLineId]);
                        $events[] = new LotImported($lot);
                    } else {
                        $lot = Lot::query()->findOrFail($r->lot_id);
                        $lot->fill($attrs)->save();
                        $events[] = new LotChanged($lot, $r->diff ?? []);
                    }
                }

                $import->rows()->where('action', 'unchanged')->whereNotNull('lot_id')->pluck('lot_id')->chunk(500)
                    ->each(fn ($ids) => Lot::query()->whereIn('id', $ids)->whereNotNull('oracle_missing_at')->update(['oracle_missing_at' => null]));
                $import->rows()->where('action', 'missing')->pluck('lot_id')->chunk(500)
                    ->each(fn ($ids) => Lot::query()->whereIn('id', $ids)->whereNull('oracle_missing_at')->update(['oracle_missing_at' => now()]));

                // A contract is missing from Oracle when none of its open/hold lots is still present in the export (M-8).
                Contract::query()->whereNull('oracle_missing_at')
                    ->whereHas('lots', fn ($q) => $q->whereIn('state', ['open', 'hold']))
                    ->whereDoesntHave('lots', fn ($q) => $q->whereIn('state', ['open', 'hold'])->whereNull('oracle_missing_at'))
                    ->update(['oracle_missing_at' => now()]);
                Contract::query()->whereNotNull('oracle_missing_at')
                    ->whereHas('lots', fn ($q) => $q->whereNull('oracle_missing_at'))
                    ->update(['oracle_missing_at' => null]);

                $import->update(['status' => 'committed', 'committed_at' => now()]);
            });
        } catch (Throwable $e) {
            $import->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            throw $e;
        }

        return $events;
    }
}
