<?php

namespace App\Modules\Foundation\Lov;

use App\Http\Controllers\Controller;
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

        if (($exact = trim((string) $request->query('exact'))) !== '') {
            if (method_exists($def, 'applyExact')) {
                $def->applyExact($query, $exact);
            } else {
                // Exact matches the code column only; a numeric needle must never match a primary key (M-13).
                $query->whereRaw('LOWER('.$def->exactColumn().') = ?', [mb_strtolower($exact)]);
            }
        } elseif (($search = trim((string) $request->query('q'))) !== '') {
            if (method_exists($def, 'applySearch')) {
                $def->applySearch($query, $search);
            } else {
                $query->where(function ($q) use ($def, $search) {
                    foreach ($def->searchColumns() as $column) {
                        $q->orWhereRaw("LOWER({$column}) LIKE ?", ['%'.mb_strtolower($search).'%']);
                    }
                });
            }
        }

        $rows = $query->orderBy($def->exactColumn())->limit(50)->get()->map(fn ($m) => $def->row($m))->values();

        return response()->json(['columns' => $def->columns(), 'data' => $rows]);
    }
}
