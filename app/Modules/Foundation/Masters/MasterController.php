<?php

namespace App\Modules\Foundation\Masters;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Query\QueryCriteria;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MasterController extends Controller
{
    public function __construct(private readonly MasterRegistry $registry) {}

    public function index(Request $request, string $resource): Response
    {
        $res = $this->registry->get($resource);
        abort_unless($request->user()->can($res->viewPermission()), 403);

        $criteria = (array) $request->query('q', []);
        $query = $res->model()::query()->with($res->with());
        QueryCriteria::apply($query, $criteria, $res->queryFields());
        $rows = $query->orderBy($res->defaultSort())->limit($res->rowLimit())->get()->map(fn ($m) => $res->toRow($m));

        return Inertia::render('masters/MasterForm', [
            'resource' => $res->meta($request->user()->can($res->editPermission())),
            'rows' => $rows,
            'criteria' => (object) $criteria,
        ]);
    }

    public function store(Request $request, string $resource): RedirectResponse
    {
        $res = $this->authorizeEdit($request, $resource);
        $data = $request->validate($res->rules(null));
        $model = DB::transaction(function () use ($res, $data) {
            $m = new ($res->model());
            $res->fill($m, $data);
            $m->save();
            $res->afterSave($m, $data);

            return $m;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('forms.saved')]);
        Inertia::flash('selectedId', $model->getKey());

        return back();
    }

    public function update(Request $request, string $resource, int $id): RedirectResponse
    {
        $res = $this->authorizeEdit($request, $resource);
        $model = $res->model()::query()->findOrFail($id);
        $data = $request->validate($res->rules($model));
        DB::transaction(function () use ($res, $model, $data) {
            $res->fill($model, $data);
            $model->save();
            $res->afterSave($model, $data);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('forms.saved')]);

        return back();
    }

    public function destroy(Request $request, string $resource, int $id): RedirectResponse
    {
        $res = $this->authorizeEdit($request, $resource);
        $model = $res->model()::query()->findOrFail($id);
        // Checks the database cannot make for us (a nullable reference that would be emptied, not refused).
        $res->assertDeletable($model);
        try {
            $model->deleteOrFail();
        } catch (QueryException $e) {
            if (! self::isForeignKeyViolation($e)) {
                throw $e;
            }
            throw ValidationException::withMessages(['record' => __('forms.in_use')]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('forms.deleted')]);

        return back();
    }

    private function authorizeEdit(Request $request, string $resource): MasterResource
    {
        $res = $this->registry->get($resource);
        abort_unless($request->user()->can($res->editPermission()), 403);

        return $res;
    }

    /**
     * Only an integrity-constraint violation (FK violation) means "in use". SQLSTATE 23000
     * is what both MySQL and SQLite report for a foreign key constraint failure.
     */
    public static function isForeignKeyViolation(QueryException $e): bool
    {
        return ($e->errorInfo[0] ?? $e->getCode()) === '23000';
    }
}
