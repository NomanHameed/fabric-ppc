<?php

namespace App\Modules\OracleSync\Services;

use App\Models\User;
use App\Modules\Foundation\Models\Party;
use App\Modules\OracleSync\Contracts\OracleLotSource;
use App\Modules\OracleSync\Data\OracleLotRow;
use App\Modules\OracleSync\Models\OracleImport;
use App\Modules\OracleSync\Models\OracleImportRow;
use App\Modules\Production\Models\Lot;
use Illuminate\Support\Facades\DB;

final class ImportPreviewService
{
    public const COMPARED = [
        'lot_no', 'po_no', 'ref_no', 'po_date', 'party_code', 'quality_code', 'quality_description', 'gsm', 'design_code',
        'design', 'colour_code', 'colour', 'g_width', 'f_width', 'po_type', 'greige_qty', 'contract_qty',
    ];

    public function __construct(private readonly PartyResolver $parties) {}

    public function preview(OracleLotSource $source, User $user, string $sourceKind = 'excel'): OracleImport
    {
        return DB::transaction(function () use ($source, $user, $sourceKind) {
            $import = OracleImport::create(['source' => $sourceKind, 'label' => $source->label(), 'uploaded_by' => $user->id]);
            $existing = Lot::query()->with(['contract.party', 'quality'])->get()->keyBy('oracle_line_id');
            $seen = [];
            $batch = [];

            foreach ($source->rows() as $rowNo => $row) {
                if (isset($seen[$row->oracleLineId])) {
                    $batch[] = $this->rowAttrs($import, $rowNo, $row, 'error', null, null,
                        'duplicate_line:'.__('oracle.errors.duplicate_line', ['line' => $row->oracleLineId]));

                    continue;
                }
                $seen[$row->oracleLineId] = true;
                $batch[] = $this->classify($import, $rowNo, $row, $existing->get($row->oracleLineId));
                if (count($batch) >= 500) {
                    OracleImportRow::insert($batch);
                    $batch = [];
                }
            }

            foreach ($existing as $lineId => $lot) {
                if (! isset($seen[$lineId]) && in_array($lot->state, ['open', 'hold'], true)) {
                    $batch[] = ['oracle_import_id' => $import->id, 'row_no' => null, 'oracle_line_id' => $lineId, 'lot_id' => $lot->id,
                        'party_code' => null, 'action' => 'missing', 'payload' => null, 'diff' => null, 'message' => null];
                }
            }
            if ($batch !== []) {
                OracleImportRow::insert($batch);
            }

            $import->recount();

            return $import->fresh();
        });
    }

    /** @param array{mode: 'link'|'create', party_id?: int, type?: string} $resolution */
    public function resolveParty(OracleImport $import, int $code, array $resolution): OracleImport
    {
        return DB::transaction(function () use ($import, $code, $resolution) {
            $rows = $import->rows()->where('action', 'error')->where('party_code', $code)->get();
            $name = $rows->first()?->payload['party_name'] ?? '';
            $resolution['mode'] === 'link'
                ? $this->parties->link($code, $name, Party::query()->findOrFail($resolution['party_id']))
                : $this->parties->create($code, $name, $resolution['type'] ?? 'brand');

            $existing = Lot::query()->with(['contract.party', 'quality'])
                ->whereIn('oracle_line_id', $rows->pluck('oracle_line_id'))->get()->keyBy('oracle_line_id');
            foreach ($rows as $r) {
                $attrs = $this->classify($import, $r->row_no, OracleLotRow::fromArray($r->payload), $existing->get($r->oracle_line_id));
                $r->update(collect($attrs)->only(['action', 'diff', 'message', 'lot_id'])->map(
                    fn ($v, $k) => in_array($k, ['diff'], true) && is_string($v) ? json_decode($v, true) : $v
                )->all());
            }
            $import->recount();

            return $import->fresh();
        });
    }

    public function discard(OracleImport $import): void
    {
        if ($import->status === 'previewed') {
            $import->update(['status' => 'discarded']);
        }
    }

    private function classify(OracleImport $import, ?int $rowNo, OracleLotRow $row, ?Lot $lot): array
    {
        if ($this->parties->resolve($row->partyCode, $row->partyName) === null) {
            return $this->rowAttrs($import, $rowNo, $row, 'error', null, null,
                'unknown_party:'.__('oracle.errors.unknown_party', ['code' => $row->partyCode, 'name' => $row->partyName]));
        }
        if ($lot === null) {
            return $this->rowAttrs($import, $rowNo, $row, 'new');
        }
        $diff = $this->diff($this->snapshot($lot), $this->incoming($row));

        return $this->rowAttrs($import, $rowNo, $row, $diff === [] ? 'unchanged' : 'changed', $lot->id, $diff === [] ? null : $diff);
    }

    private function rowAttrs(OracleImport $import, ?int $rowNo, OracleLotRow $row, string $action, ?int $lotId = null, ?array $diff = null, ?string $message = null): array
    {
        return [
            'oracle_import_id' => $import->id, 'row_no' => $rowNo, 'oracle_line_id' => $row->oracleLineId, 'lot_id' => $lotId,
            'party_code' => $row->partyCode, 'action' => $action, 'payload' => json_encode($row->toArray(), JSON_PRESERVE_ZERO_FRACTION),
            'diff' => $diff === null ? null : json_encode($diff, JSON_PRESERVE_ZERO_FRACTION), 'message' => $message,
        ];
    }

    private function snapshot(Lot $lot): array
    {
        return [
            'lot_no' => $lot->lot_no, 'po_no' => $lot->contract->po_no, 'ref_no' => $lot->contract->ref_no,
            'po_date' => $lot->contract->po_date->toDateString(), 'party_code' => $lot->contract->party->oracle_code,
            'quality_code' => $lot->quality->oracle_code, 'quality_description' => $lot->quality->description, 'gsm' => $lot->gsm,
            'design_code' => $lot->design_code, 'design' => $lot->design, 'colour_code' => $lot->colour_code, 'colour' => $lot->colour,
            'g_width' => $lot->g_width === null ? null : (float) $lot->g_width, 'f_width' => $lot->f_width === null ? null : (float) $lot->f_width,
            'po_type' => $lot->po_type, 'greige_qty' => (float) $lot->greige_qty, 'contract_qty' => (float) $lot->contract->contract_qty,
        ];
    }

    private function incoming(OracleLotRow $row): array
    {
        return collect($row->toArray())->only(self::COMPARED)->put('quality_description', trim($row->qualityDescription))->all();
    }

    /** @return array<string, array{0: mixed, 1: mixed}> */
    private function diff(array $old, array $new): array
    {
        $diff = [];
        foreach (self::COMPARED as $field) {
            $a = $old[$field] ?? null;
            $b = $new[$field] ?? null;
            $same = is_numeric($a) && is_numeric($b) ? round((float) $a, 2) === round((float) $b, 2) : (string) $a === (string) $b;
            if (! $same) {
                $diff[$field] = [is_numeric($a) && ! is_string($a) ? (float) $a : $a, is_numeric($b) && ! is_string($b) ? (float) $b : $b];
            }
        }

        return $diff;
    }
}
