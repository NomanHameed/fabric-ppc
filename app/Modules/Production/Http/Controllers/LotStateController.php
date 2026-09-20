<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Services\LotStateService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Hold / release / cancel a lot (`production.hold`). Errors land on the `state` field and the status line. */
class LotStateController extends Controller
{
    public function __construct(private readonly LotStateService $states) {}

    public function hold(Request $request, Lot $lot): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:40']]);

        return $this->run(
            fn () => $this->states->hold($lot, $data['reason'], $request->user()),
            __('production.state.held', ['lot' => $lot->lot_no]),
        );
    }

    public function release(Request $request, Lot $lot): RedirectResponse
    {
        return $this->run(
            fn () => $this->states->release($lot, $request->user()),
            __('production.state.released', ['lot' => $lot->lot_no]),
        );
    }

    public function cancel(Request $request, Lot $lot): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:40']]);

        return $this->run(
            fn () => $this->states->cancel($lot, $data['reason'], $request->user()),
            __('production.state.cancelled', ['lot' => $lot->lot_no]),
        );
    }

    /** @param  callable(): void  $action */
    private function run(callable $action, string $message): RedirectResponse
    {
        try {
            $action();
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['state' => $e->getMessage()]);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
