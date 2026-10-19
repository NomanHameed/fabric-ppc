<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\Stage;
use App\Modules\Foundation\Models\StageProcess;
use Illuminate\Http\JsonResponse;

/**
 * The stage / stage process read API (spec §16): a stage list, one stage with its processes, and the
 * processes of a stage on their own, so no screen hard-codes the options in Vue.
 *
 * `index` is what `StageSelect` loads, and its rows carry `status_aliases`: resolution is stage-only, so the
 * statuses a stage claims live on the stage, not on its processes, and the Unmapped Statuses screen shows
 * them beside the select. `show` and `processes` serve a caller that needs the Oracle production lines of a
 * stage; nothing in the app reads one to learn the status mapping.
 *
 * A stage process row is an Oracle production line now (migration `2026_10_13_000100`, a deliberate
 * user-directed restructure), so its payload is the twenty Oracle fields and not a code and a name. It has
 * no `is_active` of its own either, which is why the lists below no longer filter on one.
 *
 * Shape follows the app's other JSON endpoints (`LovController`, `MachineUtilisationController`): plain
 * arrays under `data`, no Resource layer — this application has none.
 *
 * Only active stages are listed, because the list exists to be chosen from. A stage addressed by id is
 * returned whether or not it is active: a lot can still carry a stage that was deactivated after it was
 * assigned, and a screen showing that lot must be able to name it.
 */
class StageController extends Controller
{
    public function index(): JsonResponse
    {
        $stages = Stage::query()->where('is_active', true)->withCount('processes')
            ->orderBy('sort_order')->orderBy('code')->get();

        return response()->json(['data' => $stages->map(fn (Stage $s): array => self::stage($s))->values()]);
    }

    public function show(Stage $stage): JsonResponse
    {
        $processes = $stage->processes()->get();

        return response()->json(['data' => self::stage($stage) + [
            'processes' => $processes->map(fn (StageProcess $p): array => self::process($p))->values(),
        ]]);
    }

    /** The Oracle production lines of one stage. */
    public function processes(Stage $stage): JsonResponse
    {
        $processes = $stage->processes()->get();

        return response()->json(['data' => $processes->map(fn (StageProcess $p): array => self::process($p))->values()]);
    }

    /** @return array<string, mixed> */
    public static function stage(Stage $stage): array
    {
        return [
            'id' => (int) $stage->getKey(),
            'code' => $stage->code,
            'name' => $stage->name,
            'description' => $stage->description,
            'sort_order' => (int) $stage->sort_order,
            // The mapping itself: the statuses this stage claims. Resolution is stage-only, so this is the
            // column a screen must be able to show before it adds a status to it.
            'status_aliases' => $stage->aliasList(),
            'is_active' => (bool) $stage->is_active,
            // Absent unless the caller asked for the count (index does).
            'processes_count' => $stage->getAttribute('processes_count') === null ? null : (int) $stage->getAttribute('processes_count'),
        ];
    }

    /**
     * One Oracle production line. The quantities are JSON numbers, never the decimal cast's `"300.00"`
     * string, and a null column stays null rather than becoming a zero nobody entered.
     *
     * `date_oracle` stays ISO `Y-m-d` here, deliberately, while the master screen shows and takes `d-m-Y`:
     * this is a machine-readable read API whose callers format for display themselves (the app's other JSON
     * endpoints — `MachineUtilisationController`, the OracleSync payloads — send ISO dates too, and ISO is
     * what sorts and parses in JS). `d-m-Y` belongs to the payloads that are rendered straight into a cell:
     * the contract, lot and inquiry screens, the flat lot rows, and this table's own master screen.
     *
     * @return array<string, mixed>
     */
    public static function process(StageProcess $process): array
    {
        return [
            'id' => (int) $process->getKey(),
            'stage_id' => (int) $process->stage_id,
            'party_oracle_id' => $process->party_oracle_id,
            'party_oracle_name' => $process->party_oracle_name,
            'po_no' => $process->po_no,
            'ref' => $process->ref,
            'design_oracle_id' => $process->design_oracle_id,
            'color_oracle_id' => $process->color_oracle_id,
            'color_oracle_name' => $process->color_oracle_name,
            'clr_status_oracle' => $process->clr_status_oracle,
            'po_type_oracle' => $process->po_type_oracle,
            'date_oracle' => $process->date_oracle?->format('Y-m-d'),
            'lot_no_oracle' => $process->lot_no_oracle,
            'quality' => $process->quality,
            'gw_oracle' => self::number($process->gw_oracle),
            'status_oracle' => $process->status_oracle,
            'greige_received_oracle' => self::number($process->greige_received_oracle),
            'greige_in_stock_oracle' => self::number($process->greige_in_stock_oracle),
            'qty_issued' => self::number($process->qty_issued),
            'wip' => self::number($process->wip),
            'finished' => self::number($process->finished),
            'dispatch' => self::number($process->dispatch),
        ];
    }

    /** A decimal column as a JSON number, with null left null. */
    private static function number(?string $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
