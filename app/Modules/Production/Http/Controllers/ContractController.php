<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Query\QueryCriteria;
use App\Modules\Production\Models\Contract;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The POs / Contracts screen (spec §4.4 screen 4, §4.2 query mode): one Oracle-Forms record per PO, under
 * `inquiry.view`. Read-only — every column of a contract belongs to Oracle — so the toolbar's Save, New and
 * Delete are off and there is no write route; each row links to the PO Inquiry for the lot-by-lot detail.
 *
 * The per-PO lot totals are summed **in the database** over every lot of the PO (the same shape
 * `InquiryController::contract()` uses for its pinned totals row), so they stay correct however many lots a PO
 * has and whether or not the PO's own row made the ROW_CAP page. The grid is capped at ROW_CAP rows with the
 * true match count beside it ("showing n of m").
 */
class ContractController extends Controller
{
    /** Rows the grid shows at most ("showing n of m"); the count beside it is the true number of matches. */
    public const ROW_CAP = 200;

    /** @var list<string> the lot columns a PO row totals (spec §5.3 "Derived lot values") */
    private const QTY_COLUMNS = ['greige_qty', 'stock_qty', 'issued_qty', 'wip_qty', 'finished_qty', 'dispatched_qty', 'loss_qty'];

    /** The query-mode fields (spec §4.2), over the contract and its joined party. */
    private const CRITERIA = [
        'po_no' => ['column' => 'contracts.po_no', 'type' => 'number'],
        'party' => ['column' => 'parties.name', 'type' => 'text'],
        'ref_no' => ['column' => 'contracts.ref_no', 'type' => 'text'],
        'po_date' => ['column' => 'contracts.po_date', 'type' => 'date'],
        // a timestamp, not a date: `datetime` compares the day the user typed against the column's date part
        'oracle_missing_at' => ['column' => 'contracts.oracle_missing_at', 'type' => 'datetime'],
    ];

    public function index(Request $request): Response
    {
        $criteria = (array) $request->query('q', []);
        $query = Contract::query()->with('party')
            ->join('parties', 'parties.id', '=', 'contracts.party_id')
            ->select('contracts.*')
            ->withCount('lots');
        foreach (self::QTY_COLUMNS as $column) {
            $query->withSum('lots as '.$column, $column);
        }
        QueryCriteria::apply($query, $criteria, self::CRITERIA);
        $total = (clone $query)->count();
        $contracts = $query->orderByDesc('contracts.po_date')->orderByDesc('contracts.po_no')->limit(self::ROW_CAP)->get();

        return Inertia::render('production/Contracts', [
            'contracts' => $contracts->map(fn (Contract $c): array => [
                'id' => $c->id, 'po_no' => $c->po_no, 'party' => $c->party->name, 'ref_no' => $c->ref_no,
                'po_date' => $c->po_date->format('d-m-Y'), 'contract_qty' => (float) $c->contract_qty,
                'lots_count' => (int) $c->getAttribute('lots_count'),
                'oracle_missing_at' => $c->oracle_missing_at?->format('d-m-Y'),
            ] + $this->totals($c))->values(),
            'contractsTotal' => $total,
            'criteria' => (object) $criteria,
        ]);
    }

    /**
     * The PO's lot totals, rounded like every other metre figure on screen.
     *
     * @return array<string, float>
     */
    private function totals(Contract $contract): array
    {
        $out = [];
        foreach (self::QTY_COLUMNS as $column) {
            $out[$column] = round((float) ($contract->getAttribute($column) ?? 0), 2);
        }

        return $out;
    }
}
