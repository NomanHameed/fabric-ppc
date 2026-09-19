<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\Party;
use App\Modules\OracleSync\Models\OracleImport;
use App\Modules\Production\Models\Lot;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        $open = Lot::query()->whereIn('state', ['open', 'hold']);
        $last = OracleImport::query()->with('uploader')->where('status', 'committed')->latest('committed_at')->first();

        return Inertia::render('Dashboard', [
            'stats' => [
                'open_lots' => (clone $open)->count(),
                'open_contracts' => (clone $open)->distinct('contract_id')->count('contract_id'),
                'open_greige_qty' => (float) (clone $open)->sum('greige_qty'),
                'parties' => Party::query()->where('is_active', true)->count(),
                'missing_from_oracle' => (clone $open)->whereNotNull('oracle_missing_at')->count(),
            ],
            'lastImport' => $last ? [
                'id' => $last->id, 'label' => $last->label, 'committed_at' => $last->committed_at->format('d-m-Y H:i'),
                'uploader' => $last->uploader?->name, 'new_count' => $last->new_count, 'changed_count' => $last->changed_count,
                'missing_count' => $last->missing_count,
            ] : null,
        ]);
    }
}
