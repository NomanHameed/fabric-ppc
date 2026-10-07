<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\StageProcess;
use Illuminate\Http\JsonResponse;

/**
 * One stage process by id, with the stage it belongs to (spec §16). Returned whether or not it is active:
 * a lot can carry a process deactivated after it was assigned, and the screen must still be able to name it.
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
