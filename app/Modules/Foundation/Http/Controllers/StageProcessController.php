<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\StageProcess;
use Illuminate\Http\JsonResponse;

/**
 * One Oracle production line by id, with the stage it belongs to (spec §16).
 *
 * The row's payload is the twenty Oracle fields: `stage_processes` was restructured by the user (migration
 * `2026_10_13_000100`) and carries no code, name or `is_active` any more, so there is nothing left to filter
 * on here either.
 */
class StageProcessController extends Controller
{
    public function show(StageProcess $stageProcess): JsonResponse
    {
        $stageProcess->loadMissing('stage');
        $stage = $stageProcess->stage;

        return response()->json(['data' => StageController::process($stageProcess) + [
            'stage' => $stage === null ? null : StageController::stage($stage),
        ]]);
    }
}
