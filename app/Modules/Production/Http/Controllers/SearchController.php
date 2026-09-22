<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Models\Contract;
use App\Modules\Production\Models\Lot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The top-bar Lot / PO search (spec §4.1, §6.3.2): the same exact-lookup rules as the lots LOV (LotLov::applyExact) —
 * a full lot number, its last 4 digits as written on floor sheets, or a PO number — across every lot state. Exactly one
 * lot and no PO redirects to the Lot Inquiry; exactly one PO and no lot to the PO Inquiry; anything else (several lots
 * sharing a number, a number that is both a lot and a PO, or nothing at all) shows the chooser page.
 */
class SearchController extends Controller
{
    /** Lots the chooser lists at most ("showing n of m"). */
    public const ROW_CAP = 200;

    public function __invoke(Request $request): Response|RedirectResponse
    {
        $raw = $request->query('q');
        $q = is_string($raw) ? trim($raw) : '';
        $lots = collect();
        $contracts = collect();
        $lotsTotal = 0;
        if ($q !== '' && ctype_digit($q)) {
            $lotQuery = Lot::query()->with(['contract.party', 'quality'])
                ->when(strlen($q) <= 4, fn ($b) => $b->where('lot_no', 'like', '%'.$q), fn ($b) => $b->where('lot_no', $q));
            $lotsTotal = (clone $lotQuery)->count();
            $lots = $lotQuery->orderByDesc('id')->limit(self::ROW_CAP)->get();
            $contracts = strlen($q) > 4 ? Contract::query()->with('party')->where('po_no', (int) $q)->get() : collect();
        }
        if ($lots->count() === 1 && $contracts->isEmpty()) {
            return redirect()->route('inquiry.lot', $lots->first());
        }
        if ($contracts->count() === 1 && $lots->isEmpty()) {
            return redirect()->route('inquiry.contract', $contracts->first());
        }

        return Inertia::render('inquiry/SearchResults', [
            'q' => $q,
            'lots' => $lots->map(fn (Lot $l): array => [
                'id' => $l->id, 'lot_no' => $l->lot_no, 'po_no' => $l->contract->po_no, 'party' => $l->contract->party->name,
                'design' => $l->design, 'colour' => $l->colour, 'quality' => $l->quality->description, 'state' => $l->state, 'current_label' => $l->current_label,
                'stock_qty' => (float) $l->stock_qty, 'wip_qty' => (float) $l->wip_qty, 'finished_qty' => (float) $l->finished_qty,
            ])->values(),
            'lotsTotal' => $lotsTotal,
            'contracts' => $contracts->map(fn (Contract $c): array => [
                'id' => $c->id, 'po_no' => $c->po_no, 'party' => $c->party->name, 'ref_no' => $c->ref_no, 'po_date' => $c->po_date->format('d-m-Y'),
            ])->values(),
        ]);
    }
}
