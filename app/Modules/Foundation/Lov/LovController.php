<?php

namespace App\Modules\Foundation\Lov;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\Party;
use App\Modules\Foundation\Support\PartyName;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LovController extends Controller
{
    public function show(string $name, Request $request, LovRegistry $registry): JsonResponse
    {
        $def = $registry->get($name);
        if ($def->permission() !== null) {
            abort_unless($request->user()->can($def->permission()), 403);
        }

        $filters = array_filter((array) $request->input('filters', []), fn ($v) => $v !== null && $v !== '');
        $query = $def->query($filters);

        // Special handling for parties: exact match via alias path
        if ($name === 'parties' && ($exact = trim((string) $request->query('exact'))) !== '') {
            $party = Party::findByName($exact);

            return response()->json(['columns' => $def->columns(), 'data' => $party ? [$def->row($party)] : []]);
        }

        if (($exact = trim((string) $request->query('exact'))) !== '') {
            $query->where(function ($q) use ($def, $exact) {
                $q->whereRaw('LOWER('.$def->exactColumn().') = ?', [mb_strtolower($exact)]);
                if (ctype_digit($exact)) {
                    $q->orWhere($q->getModel()->getQualifiedKeyName(), (int) $exact);
                }
            });
        } elseif (($search = trim((string) $request->query('q'))) !== '') {
            $needle = $name === 'parties' ? PartyName::normalize($search) : $search;
            $query->where(function ($q) use ($def, $needle) {
                foreach ($def->searchColumns() as $column) {
                    $q->orWhereRaw("LOWER({$column}) LIKE ?", ['%'.mb_strtolower($needle).'%']);
                }
            });
        }

        $rows = $query->orderBy($def->exactColumn())->limit(50)->get()->map(fn ($m) => $def->row($m))->values();

        return response()->json(['columns' => $def->columns(), 'data' => $rows]);
    }
}
