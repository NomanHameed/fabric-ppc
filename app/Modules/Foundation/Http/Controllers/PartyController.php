<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Masters\MasterController;
use App\Modules\Foundation\Masters\OracleOwned;
use App\Modules\Foundation\Models\Party;
use App\Modules\Foundation\Models\PartyAlias;
use App\Modules\Foundation\Query\QueryCriteria;
use App\Modules\Foundation\Support\PartyName;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PartyController extends Controller
{
    /** The master key this screen shares with `OracleOwned` (it has no `MasterResource`: the screen is its own). */
    private const MASTER = 'parties';

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('masters.view'), 403);
        $criteria = (array) $request->query('q', []);
        if (is_string($criteria['name'] ?? null) && $criteria['name'] !== '') {
            $criteria['name'] = PartyName::normalize($criteria['name']);
        }
        $query = Party::query()->with('aliases');
        QueryCriteria::apply($query, $criteria, [
            'name' => ['column' => 'name_normalized', 'type' => 'text'],
            'oracle_code' => ['column' => 'oracle_code', 'type' => 'number'],
            'type' => ['column' => 'type', 'type' => 'text'],
        ]);

        return Inertia::render('masters/Parties', [
            'parties' => $query->orderBy('name_normalized')->limit(2000)->get()->map(fn (Party $p) => [
                'id' => $p->id, 'oracle_code' => $p->oracle_code, 'name' => $p->name, 'type' => $p->type,
                'is_active' => $p->is_active, 'aliases' => $p->aliases->map->only(['id', 'alias'])->values(),
            ]),
            'canEdit' => $request->user()->can('masters.manage'),
            'criteria' => (object) $request->query('q', []),
        ] + OracleOwned::meta(self::MASTER));
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('masters.manage'), 403);
        // Oracle owns a party's identity: new parties come from the import's resolve/create flow, not from here.
        OracleOwned::assertCanCreate(self::MASTER);
        $party = $this->save(new Party, $this->validated($request, null));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('forms.saved')]);
        Inertia::flash('selectedId', $party->id);

        return back();
    }

    public function update(Request $request, Party $party): RedirectResponse
    {
        abort_unless($request->user()->can('masters.manage'), 403);
        // Aliases, type and is_active stay editable; `oracle_code` and `name` are Oracle's and are dropped here.
        $data = OracleOwned::assertCanUpdate(self::MASTER, $party, $this->validated($request, $party));
        $this->save($party, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('forms.saved')]);

        return back();
    }

    public function destroy(Request $request, Party $party): RedirectResponse
    {
        abort_unless($request->user()->can('masters.manage'), 403);
        // Refused while Oracle owns the parties master; the in-use mapping below is what a user sees once a
        // party master is app-owned again (it is also what a party created in error would have needed).
        OracleOwned::assertCanDelete(self::MASTER);
        try {
            $party->deleteOrFail();
        } catch (QueryException $e) {
            if (! MasterController::isForeignKeyViolation($e)) {
                throw $e;
            }
            throw ValidationException::withMessages(['record' => __('forms.in_use')]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('forms.deleted')]);

        return back();
    }

    private function validated(Request $request, ?Party $party): array
    {
        $request->merge(['aliases' => array_values(array_filter(array_map('trim', (array) $request->input('aliases', [])), fn ($v) => $v !== ''))]);
        $data = $request->validate([
            'oracle_code' => ['nullable', 'integer', Rule::unique('parties', 'oracle_code')->ignore($party?->id)],
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::in(['brand', 'local'])],
            'is_active' => ['boolean'],
            'aliases' => ['array'],
            'aliases.*' => ['string', 'max:150'],
        ]);

        $normalized = PartyName::normalize($data['name']);
        if (Party::query()->where('name_normalized', $normalized)->when($party, fn ($q) => $q->whereKeyNot($party->id))->exists()) {
            throw ValidationException::withMessages(['name' => __('validation.unique', ['attribute' => 'name'])]);
        }
        $seen = [];
        foreach ($data['aliases'] as $i => $alias) {
            $normalizedAlias = PartyName::normalize($alias);
            if (in_array($normalizedAlias, $seen, true)) {
                throw ValidationException::withMessages(["aliases.{$i}" => __('validation.distinct', ['attribute' => 'alias'])]);
            }
            $seen[] = $normalizedAlias;

            $taken = PartyAlias::query()->where('alias_normalized', $normalizedAlias)
                ->when($party, fn ($q) => $q->where('party_id', '!=', $party->id))->exists();
            if ($taken) {
                throw ValidationException::withMessages(["aliases.{$i}" => __('validation.unique', ['attribute' => 'alias'])]);
            }
        }

        return $data;
    }

    private function save(Party $party, array $data): Party
    {
        return DB::transaction(function () use ($party, $data) {
            $party->fill(collect($data)->except('aliases')->all())->save();
            $keep = collect($data['aliases'])->map(fn ($a) => PartyName::normalize($a))->all();
            // Delete per model so owen-it records a `deleted` audit per alias (M-8).
            $party->aliases()->whereNotIn('alias_normalized', $keep)->get()->each->delete();
            foreach ($data['aliases'] as $alias) {
                $party->aliases()->updateOrCreate(['alias_normalized' => PartyName::normalize($alias)], ['alias' => $alias]);
            }

            return $party;
        });
    }
}
