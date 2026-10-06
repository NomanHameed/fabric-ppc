<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Timeline\TimelineEventType;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The screen that draws a lot's timeline. It carries the lot's identity only — the events themselves come from
 * `production.lots.timeline` (JSON), because the type filter changes them without changing the lot.
 */
class LotTimelineScreenController extends Controller
{
    public function index(Request $request): Response
    {
        $lot = $request->filled('lot')
            ? Lot::query()->with(['contract.party', 'quality'])->find($request->integer('lot'))
            : null;

        return Inertia::render('production/LotTimeline', [
            'lot' => $lot === null ? null : [
                'id' => $lot->id,
                'lot_no' => $lot->lot_no,
                'po_no' => $lot->contract->po_no,
                'contract_id' => $lot->contract_id,
                'party' => $lot->contract->party->name,
                'quality' => $lot->quality?->description,
                'design' => $lot->design,
                'colour' => $lot->colour,
                'colour_status' => $lot->colour_status,
                'po_type' => $lot->po_type,
                'state' => $lot->state,
                'current_label' => $lot->current_label,
                'greige_qty' => (float) $lot->greige_qty,
                'wip_qty' => (float) $lot->wip_qty,
                'finished_qty' => (float) $lot->finished_qty,
                'dispatched_qty' => (float) $lot->dispatched_qty,
            ],
            'types' => TimelineEventType::ALL,
        ]);
    }
}
