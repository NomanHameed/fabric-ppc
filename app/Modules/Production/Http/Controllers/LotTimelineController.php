<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Services\LotTimelineService;
use App\Modules\Production\Timeline\TimelineEventType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A lot's timeline as JSON. The data layer owns the query and the shape; the screen that draws it is separate
 * work (see the report's "needs a UI counterpart").
 */
class LotTimelineController extends Controller
{
    public function __construct(private readonly LotTimelineService $timeline) {}

    public function show(Request $request, Lot $lot): JsonResponse
    {
        $filters = $request->validate([
            'types' => ['array'],
            'types.*' => ['string', 'in:'.implode(',', TimelineEventType::ALL)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        return response()->json([
            'lot' => ['id' => $lot->id, 'lot_no' => $lot->lot_no, 'state' => $lot->state],
            'events' => $this->timeline->toArray($lot, $filters['types'] ?? [], $filters['limit'] ?? null),
        ]);
    }
}
