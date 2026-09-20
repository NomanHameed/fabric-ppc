<?php

namespace App\Modules\OracleSync\Services;

use App\Models\User;
use App\Modules\Foundation\Models\Party;
use App\Modules\OracleSync\Contracts\OracleLotSource;
use App\Modules\OracleSync\Data\OracleLotRow;
use App\Modules\OracleSync\Data\OracleRowError;
use App\Modules\OracleSync\Models\OracleImport;
use App\Modules\OracleSync\Models\OracleImportRow;
use App\Modules\Production\Models\Lot;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ImportPreviewService
{
    public const COMPARED = [
        'lot_no', 'po_no', 'ref_no', 'po_date', 'party_code', 'quality_code', 'quality_description', 'gsm', 'design_code',
        'design', 'colour_code', 'colour', 'g_width', 'f_width', 'po_type', 'greige_qty', 'contract_qty',
    ];

    public function __construct(private readonly PartyResolver $parties) {}

    /**
     * Per-run bookkeeping: party id => the first Oracle code that matched it by name/alias
     * while its oracle_code was still null. A later, different code matching the same
     * uncoded party is left unresolved instead of silently sharing that party.
     *
     * @var array<int, int>
     */
    private array $nameMatchedPartyIds = [];

    public function preview(OracleLotSource $source, User $user, string $sourceKind = 'excel'): OracleImport
    {
        $this->nameMatchedPartyIds = [];

        return DB::transaction(function () use ($source, $user, $sourceKind) {
            $import = OracleImport::create(['source' => $sourceKind, 'label' => $source->label(), 'uploaded_by' => $user->id]);
            $existing = Lot::query()->with(['contract.party', 'quality'])->get()->keyBy('oracle_line_id');
            $seen = [];
            $batch = [];

            foreach ($source->rows() as $rowNo => $row) {
                if ($row instanceof OracleRowError) {
                    // An unparseable source row is recorded, not fatal (M-6). `message` is 255 chars: keep the reason short.
                    $batch[] = ['oracle_import_id' => $import->id, 'row_no' => $row->rowNo, 'oracle_line_id' => $row->oracleLineId, 'lot_id' => null,
                        'party_code' => null, 'action' => 'error', 'payload' => null, 'diff' => null,
                        'message' => 'bad_row:'.__('oracle.errors.bad_row', ['row' => $row->rowNo, 'reason' => Str::limit($row->message, 150)])];
                    if ($row->oracleLineId !== null) {
                        // The line is in the file, just unreadable: do not also report its lot as missing.
                        $seen[$row->oracleLineId] = true;
                    }

                    continue;
                }
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
        if ($import->status !== 'previewed') {
            throw new DomainException(__('oracle.errors.not_previewed'));
        }

        return DB::transaction(function () use ($import, $code, $resolution) {
            $rows = $import->rows()->where('action', 'error')->where('party_code', $code)
                ->where('message', 'like', 'unknown_party%')->get();

            if ($rows->isEmpty()) {
                throw ValidationException::withMessages(['code' => __('oracle.errors.unknown_party', ['code' => $code, 'name' => ''])]);
            }

            $name = $rows->first()?->payload['party_name'] ?? '';
            if ($resolution['mode'] === 'link') {
                $target = Party::query()->findOrFail($resolution['party_id']);
                $this->assertPartyNotClaimedByAnotherCode($import, $code, $target);
                $this->parties->link($code, $name, $target);
            } else {
                $this->parties->create($code, $name, $resolution['type'] ?? 'brand');
            }

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

    /**
     * A party can hold only one Oracle code. Refuse linking $code to $target when some other,
     * distinct code among this import's resolved rows already resolves to the same uncoded party
     * by name/alias — that other code implicitly claimed it first (see PartyResolver::resolve()).
     * A target that already carries a different explicit oracle_code is refused by link() itself.
     */
    private function assertPartyNotClaimedByAnotherCode(OracleImport $import, int $code, Party $target): void
    {
        if ($target->oracle_code !== null) {
            return;
        }

        $otherCodes = $import->rows()->whereIn('action', ['new', 'changed', 'unchanged'])
            ->whereNotNull('party_code')->where('party_code', '!=', $code)
            ->distinct()->pluck('party_code');

        foreach ($otherCodes as $otherCode) {
            $otherName = $import->rows()->where('party_code', $otherCode)->value('payload')['party_name'] ?? '';
            if ($this->parties->resolve($otherCode, $otherName)?->id === $target->id) {
                throw ValidationException::withMessages(['party_id' => __('oracle.errors.party_claimed', ['code' => $otherCode])]);
            }
        }
    }

    private function classify(OracleImport $import, ?int $rowNo, OracleLotRow $row, ?Lot $lot): array
    {
        if (($fields = $row->overlong()) !== []) {
            return $this->rowAttrs($import, $rowNo, $row, 'error', null, null,
                'overlong:'.__('oracle.errors.overlong', ['fields' => implode(', ', $fields)]));
        }
        $party = $this->parties->resolve($row->partyCode, $row->partyName);

        if ($party !== null && $party->oracle_code === null) {
            // Matched by name/alias while the party is still uncoded: only the first
            // Oracle code to claim it this run may use it; a later code is unresolved.
            if (isset($this->nameMatchedPartyIds[$party->id]) && $this->nameMatchedPartyIds[$party->id] !== $row->partyCode) {
                $party = null;
            } else {
                $this->nameMatchedPartyIds[$party->id] ??= $row->partyCode;
            }
        }

        if ($party === null) {
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
