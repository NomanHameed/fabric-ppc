<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Masters\MasterController;
use App\Modules\Foundation\Models\LookupValue;
use App\Modules\Foundation\Models\RouteTemplate;
use App\Modules\Foundation\Query\QueryCriteria;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RouteTemplateController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('masters.view'), 403);

        $criteria = (array) $request->query('q', []);
        $query = RouteTemplate::query()->with('steps.operation.department');
        QueryCriteria::apply($query, $criteria, [
            'name' => ['column' => 'name', 'type' => 'text'],
            'po_type' => ['column' => 'po_type', 'type' => 'text'],
            'quality_pattern' => ['column' => 'quality_pattern', 'type' => 'text'],
        ]);

        return Inertia::render('masters/RouteTemplates', [
            'templates' => $query->orderByDesc('priority')->orderBy('name')->get()
                ->map(fn (RouteTemplate $t) => [
                    'id' => $t->id, 'name' => $t->name, 'po_type' => $t->po_type, 'quality_pattern' => $t->quality_pattern,
                    'priority' => $t->priority, 'is_active' => $t->is_active,
                    'steps' => $t->steps->map(fn ($s) => [
                        'seq' => $s->seq, 'operation_id' => $s->operation_id,
                        'operation' => $s->operation->name, 'department' => $s->operation->department->name,
                    ])->values(),
                ]),
            'poTypes' => LookupValue::list('po_type')->get()->map(fn ($v) => ['value' => $v->code, 'label' => $v->label]),
            'canEdit' => $request->user()->can('masters.manage'),
            'criteria' => (object) $request->query('q', []),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('masters.manage'), 403);
        $this->save(new RouteTemplate, $this->validated($request, null));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('forms.saved')]);

        return back();
    }

    public function update(Request $request, RouteTemplate $routeTemplate): RedirectResponse
    {
        abort_unless($request->user()->can('masters.manage'), 403);
        $this->save($routeTemplate, $this->validated($request, $routeTemplate));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('forms.saved')]);

        return back();
    }

    public function destroy(Request $request, RouteTemplate $routeTemplate): RedirectResponse
    {
        abort_unless($request->user()->can('masters.manage'), 403);
        try {
            $routeTemplate->deleteOrFail();
        } catch (QueryException $e) {
            if (! MasterController::isForeignKeyViolation($e)) {
                throw $e;
            }
            throw ValidationException::withMessages(['record' => __('forms.in_use')]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('forms.deleted')]);

        return back();
    }

    private function validated(Request $request, ?RouteTemplate $template): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('route_templates', 'name')->ignore($template?->id)],
            'po_type' => ['nullable', 'string', 'max:40'],
            'quality_pattern' => ['nullable', 'string', 'max:120'],
            'priority' => ['required', 'integer', 'between:-100,100'],
            'is_active' => ['boolean'],
            'steps' => ['required', 'array', 'min:1'],
            'steps.*.operation_id' => ['required', 'integer', 'exists:operations,id'],
        ]);
    }

    private function save(RouteTemplate $template, array $data): void
    {
        DB::transaction(function () use ($template, $data) {
            $template->fill(collect($data)->except('steps')->all())->save();
            $template->steps()->delete();
            foreach (array_values($data['steps']) as $i => $step) {
                $template->steps()->create(['seq' => ($i + 1) * 10, 'operation_id' => $step['operation_id']]);
            }
        });
    }
}
