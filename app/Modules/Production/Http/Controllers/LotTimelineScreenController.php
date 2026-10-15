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
            ? Lot::query()->with('contract.party')->find($request->integer('lot'))
            : null;

        return Inertia::render('production/LotTimeline', [
            'lot' => $lot === null ? null : [
                'id' => $lot->id,
                'lot_no_oracle' => $lot->lot_no_oracle,
                'po_no' => $lot->contract->po_no,
                'contract_id' => $lot->contract_id,
                'party' => $lot->contract->party->name,
                'quality' => $lot->quality,
                'design' => $lot->design,
                'color_oracle_name' => $lot->color_oracle_name,
                'clr_status_oracle' => $lot->clr_status_oracle,
                'po_type_oracle' => $lot->po_type_oracle,
                'state' => $lot->state,
                'status_oracle' => $lot->status_oracle,
                'greige_received_oracle' => (float) $lot->greige_received_oracle,
                'wip' => (float) $lot->wip,
                'finished' => (float) $lot->finished,
                'dispatch' => (float) $lot->dispatch,
            ],
            'types' => TimelineEventType::ALL,
        ]);
    }
}
