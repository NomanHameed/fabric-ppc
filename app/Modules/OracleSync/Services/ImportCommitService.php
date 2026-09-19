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
use DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ImportCommitService
{
    public function __construct(private readonly PartyResolver $parties) {}

    public function commit(OracleImport $import, User $user): void
    {
        $import->refresh();
        if ($import->error_count > 0) {
            throw new DomainException(__('oracle.errors.has_errors'));
        }
        $stale = OracleImport::query()->where('status', 'committed')->where('committed_at', '>', $import->created_at)->exists();
        if ($stale) {
            throw new DomainException(__('oracle.errors.stale'));
        }

        $committing = OracleImport::query()->whereKey($import->id)->where('status', 'previewed')->update(['status' => 'committing']);
        if ($committing !== 1) {
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

                $import->update(['status' => 'committed', 'committed_at' => now()]);
            });
        } catch (Throwable $e) {
            $import->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            throw $e;
        }

        foreach ($events as $event) {
            event($event);
        }
    }
}
