<?php

namespace App\Modules\Activity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Activity\Models\UserAction;
use App\Modules\Activity\Models\UserDevice;
use App\Modules\Activity\Support\ActionKeys;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reads the action log.
 *
 * One route, two representations. An ordinary visit renders the Inertia screen with the first page already in
 * the payload, so the log is readable without JavaScript having to fetch anything; a caller that asks for JSON
 * (`Accept: application/json`) gets the same page of rows as data. Inertia's own requests send
 * `Accept: text/html`, so a partial reload or a filter change still comes back as a page — `wantsJson()`, not
 * `expectsJson()`, is what separates them (Inertia sets X-Requested-With, which `expectsJson()` honours).
 *
 * Paging is always server-side: the log grows without limit, so nothing here ever returns the whole table.
 */
class UserActionController extends Controller
{
    public const PER_PAGE = 50;

    public function index(Request $request): Response|JsonResponse
    {
        $filters = $this->filters($request);
        $page = $this->page($filters);

        if ($request->wantsJson()) {
            return response()->json($this->payload($page));
        }

        return Inertia::render('activity/ActionLog', [
            ...$this->payload($page),
            'filters' => [
                'user_id' => $filters['user_id'] ?? null,
                'action' => $filters['action'] ?? null,
                'device_id' => $filters['device_id'] ?? null,
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
            ],
            'options' => [
                'users' => User::query()->orderBy('name')->get(['id', 'name'])
                    ->map(fn (User $u) => ['value' => $u->id, 'label' => $u->name])->values(),
                // The short name, not the sentence: `activity.actions.*` is a template full of `:lot`/`:qty`
                // placeholders that only make sense once a row fills them in.
                'actions' => collect(ActionKeys::ALL)
                    ->map(fn (string $key) => ['value' => $key, 'label' => __('activity.action_names.'.$key)])->values(),
                'devices' => UserDevice::query()->orderBy('computer_name')->get()
                    ->map(fn (UserDevice $d) => ['value' => $d->device_id, 'label' => $d->label()])->values(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'action' => ['nullable', Rule::in(ActionKeys::ALL)],
            'subject_type' => ['nullable', 'string', 'max:255'],
            'subject_id' => ['nullable', 'integer'],
            'device_id' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, UserAction>
     */
    private function page(array $filters): LengthAwarePaginator
    {
        return UserAction::query()->with(['user:id,name', 'device:id,device_id,computer_name'])
            ->when($filters['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($filters['action'] ?? null, fn ($q, $v) => $q->where('action', $v))
            ->when($filters['subject_type'] ?? null, fn ($q, $v) => $q->where('subject_type', $v))
            ->when($filters['subject_id'] ?? null, fn ($q, $v) => $q->where('subject_id', $v))
            ->when($filters['device_id'] ?? null, fn ($q, $v) => $q->where('device_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('occurred_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('occurred_at', '<=', $v))
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->paginate($filters['per_page'] ?? self::PER_PAGE)->withQueryString();
    }

    /**
     * @param  LengthAwarePaginator<int, UserAction>  $page
     * @return array<string, mixed>
     */
    private function payload(LengthAwarePaginator $page): array
    {
        return [
            // items(), not getCollection(): the Eloquent builder returns the *contract*, which has no
            // getCollection().
            'data' => collect($page->items())->map(fn (UserAction $a) => [
                'id' => $a->id,
                'action' => $a->action,
                'label' => $a->label(),
                'description' => $a->description,
                // The name as it stood when the action happened, so a rename — or a deleted account — does not
                // rewrite history; the live relation is only a fallback for rows written before the column.
                'user' => $a->user_name ?? $a->user?->name,
                'subject_type' => $a->subject_type,
                'subject_id' => $a->subject_id,
                'ip_address' => $a->ip_address,
                'device_id' => $a->device_id,
                'computer_name' => $a->computer_name ?? $a->device?->computer_name,
                'occurred_at' => $a->occurred_at->toIso8601String(),
            ])->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
            ],
        ];
    }
}
