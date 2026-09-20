<?php

namespace App\Modules\Foundation\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Foundation\Access\Permissions;
use App\Modules\Foundation\Models\Department;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/Users', [
            'users' => User::query()->with(['roles', 'departments'])->orderBy('name')->get()->map(fn (User $u) => [
                'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'is_active' => $u->is_active, 'locale' => $u->locale,
                'roles' => $u->getRoleNames()->values(), 'department_ids' => $u->departments->pluck('id')->values(),
            ]),
            'roles' => array_keys(Permissions::ROLES),
            'departments' => Department::query()->orderBy('sort')->get()->map(fn ($d) => ['value' => $d->id, 'label' => $d->name]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);
        abort_if(
            ! $request->user()->hasRole(Permissions::ROLE_ADMIN) && in_array(Permissions::ROLE_ADMIN, $data['roles'], true),
            403
        );
        DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'],
                'is_active' => $data['is_active'] ?? true, 'locale' => $data['locale'], 'email_verified_at' => now(),
            ]);
            $user->syncRoles($data['roles']);
            $user->departments()->sync($data['department_ids'] ?? []);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('forms.saved')]);

        return back();
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $this->validated($request, $user);
        abort_if(
            ! $request->user()->hasRole(Permissions::ROLE_ADMIN)
                && (in_array(Permissions::ROLE_ADMIN, $data['roles'], true) || $user->hasRole(Permissions::ROLE_ADMIN)),
            403
        );
        if ($user->is($request->user())) {
            $errors = [];
            if (! ($data['is_active'] ?? true)) {
                $errors['is_active'] = __('admin.cannot_deactivate_self');
            }
            if ($user->hasRole(Permissions::ROLE_ADMIN) && ! in_array(Permissions::ROLE_ADMIN, $data['roles'], true)) {
                $errors['roles'] = __('admin.cannot_drop_own_admin');
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
        }
        DB::transaction(function () use ($user, $data) {
            $user->fill(collect($data)->only(['name', 'email', 'is_active', 'locale'])->all());
            if (! empty($data['password'])) {
                $user->password = $data['password'];
            }
            $user->save();
            $user->syncRoles($data['roles']);
            $user->departments()->sync($data['department_ids'] ?? []);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('forms.saved')]);

        return back();
    }

    private function validated(Request $request, ?User $user): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'password' => [$user ? 'nullable' : 'required', 'string', Password::min(8)],
            'is_active' => ['boolean'],
            'locale' => ['required', Rule::in(array_keys(config('app.available_locales')))],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => [Rule::in(array_keys(Permissions::ROLES))],
            'department_ids' => ['array'],
            'department_ids.*' => ['integer', 'exists:departments,id'],
        ]);
    }
}
