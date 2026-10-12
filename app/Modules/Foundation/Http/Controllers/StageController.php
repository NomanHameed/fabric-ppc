<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\Stage;
use App\Modules\Foundation\Models\StageProcess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The stage / stage process read API (spec §16): a stage list, one stage with its processes, and the
 * processes of a stage on their own, so no screen hard-codes the options in Vue.
 *
 * `index` is what `StageSelect` loads, and its rows carry `status_aliases`: resolution is stage-only, so the
 * statuses a stage claims live on the stage, not on its processes, and the Unmapped Statuses screen shows
 * them beside the select. `show` and `processes` serve a caller that needs a stage's processes; nothing in
 * the app reads a process to learn the status mapping.
 *
 * Shape follows the app's other JSON endpoints (`LovController`, `MachineUtilisationController`): plain
 * arrays under `data`, no Resource layer — this application has none.
 *
 * Only active rows are listed, because the lists exist to be chosen from. A row addressed by id is returned
 * whether or not it is active: a lot can still carry a stage that was deactivated after it was assigned, and
 * a screen showing that lot must be able to name it.
 */
class StageController extends Controller
{
    public function index(): JsonResponse
    {
        $stages = Stage::query()->where('is_active', true)
            ->withCount(['processes' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('sort_order')->orderBy('code')->get();

        return response()->json(['data' => $stages->map(fn (Stage $s): array => self::stage($s))->values()]);
    }

    public function show(Stage $stage): JsonResponse
    {
        $processes = $stage->processes()->where('is_active', true)->get();

        return response()->json(['data' => self::stage($stage) + [
            'processes' => $processes->map(fn (StageProcess $p): array => self::process($p))->values(),
        ]]);
    }

    /** The processes of one stage. `?include_inactive=1` adds the deactivated ones (an admin view wants them). */
    public function processes(Request $request, Stage $stage): JsonResponse
    {
        $processes = $stage->processes()
            ->unless($request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
            ->get();

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

    /** @return array<string, mixed> */
    public static function process(StageProcess $process): array
    {
        return [
            'id' => (int) $process->getKey(),
            'stage_id' => (int) $process->stage_id,
            'code' => $process->code,
            'name' => $process->name,
            'description' => $process->description,
            'sort_order' => (int) $process->sort_order,
            'quantity_type' => $process->quantity_type,
            'is_wip' => (bool) $process->is_wip,
            'is_finished' => (bool) $process->is_finished,
            'is_dispatch' => (bool) $process->is_dispatch,
            'status_aliases' => $process->aliasList(),
            'is_active' => (bool) $process->is_active,
        ];
    }
}
