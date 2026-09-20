<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Exceptions\InsufficientQuantity;
use App\Modules\Production\Exceptions\LotNotOpen;
use App\Modules\Production\Models\Contract;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\LotMovement;
use App\Modules\Production\Services\IssueService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Issue to Floor (`production.issue`). Ledger refusals (nothing in stock, lot not open) become field errors
 * on `qty` / `lot_id` / `contract_id`, never 500s; success flashes a toast and returns to the screen.
 */
class IssueController extends Controller
{
    private const RECENT = 100;

    public function __construct(private readonly IssueService $issues) {}

    public function index(): Response
    {
        return Inertia::render('production/IssueToFloor', [
            'recent' => LotMovement::query()->with(['lot.contract.party', 'lot.quality', 'user'])
                ->where('kind', 'issue')->latest('id')->limit(self::RECENT)->get()
                ->map(fn (LotMovement $m): array => [
                    'id' => $m->id, 'lot_id' => $m->lot_id, 'lot_no' => $m->lot->lot_no, 'po_no' => $m->lot->contract->po_no,
                    'party' => $m->lot->contract->party->name, 'design' => $m->lot->design, 'colour' => $m->lot->colour,
                    'quality' => $m->lot->quality?->description, 'stock_qty' => (float) $m->lot->stock_qty,
                    'qty' => (float) ($m->reversal_of_id === null ? $m->qty_into_to : $m->qty_out_of_from),
                    'reversal' => $m->reversal_of_id !== null, 'moved_at' => $m->moved_at->format('d-m-Y'),
                    'user' => $m->user?->name, 'remarks' => $m->reason,
                ])->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'lot_id' => ['required', 'integer', 'exists:lots,id'],
            'qty' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999.99'],
            'issued_on' => ['required', 'date_format:d-m-Y', 'before_or_equal:today'],
            'remarks' => ['nullable', 'string', 'max:200'],
        ]);
        $lot = Lot::query()->findOrFail((int) $data['lot_id']);

        try {
            $this->issues->issue($lot, (float) $data['qty'], $request->user(), self::day($data['issued_on']), $data['remarks'] ?? null);
        } catch (InsufficientQuantity $e) {
            throw ValidationException::withMessages(['qty' => $e->getMessage()]);
        } catch (LotNotOpen $e) {
            throw ValidationException::withMessages(['lot_id' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('production.issue.issued', [
            'qty' => number_format((float) $data['qty'], 2), 'lot' => $lot->lot_no,
        ])]);

        return back();
    }

    public function storeContract(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'contract_id' => ['required', 'integer', 'exists:contracts,id'],
            'issued_on' => ['required', 'date_format:d-m-Y', 'before_or_equal:today'],
        ]);
        $contract = Contract::query()->findOrFail((int) $data['contract_id']);

        try {
            $n = $this->issues->issueContract($contract, $request->user(), self::day($data['issued_on']));
        } catch (InsufficientQuantity|LotNotOpen $e) {
            throw ValidationException::withMessages(['contract_id' => $e->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => $n > 0 ? 'success' : 'info',
            'message' => __($n > 0 ? 'production.issue.issued_contract' : 'production.issue.nothing_to_issue', ['n' => $n, 'po' => $contract->po_no]),
        ]);

        return back();
    }

    /** A validated `d-m-Y` date as the day's start, in the app timezone (stored as given, never shifted). */
    private static function day(string $dmy): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!d-m-Y', $dmy);
    }
}
