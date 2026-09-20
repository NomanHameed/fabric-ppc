<?php

namespace App\Modules\Foundation\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Access\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/Roles', [
            'roles' => Role::query()->with('permissions')->orderBy('name')->get()->map(fn (Role $r) => [
                'name' => $r->name, 'permissions' => $r->permissions->pluck('name')->values(),
            ]),
            'permissions' => Permissions::ALL,
        ]);
    }

    public function update(Request $request, string $role): RedirectResponse
    {
        abort_if($role === Permissions::ROLE_ADMIN, 403);
        $model = Role::query()->where('name', $role)->where('guard_name', 'web')->firstOrFail();
        $data = $request->validate([
            'permissions' => ['array'],
            'permissions.*' => [Rule::in(Permissions::ALL)],
        ]);
        $submittedAdmin = array_filter($data['permissions'] ?? [], fn (string $p) => str_starts_with($p, 'admin.'));
        $newAdminPerms = array_diff($submittedAdmin, $model->permissions->pluck('name')->all());
        if ($newAdminPerms !== [] && ! $request->user()->hasRole(Permissions::ROLE_ADMIN)) {
            throw ValidationException::withMessages(['permissions' => __('admin.admin_permissions_admin_only')]);
        }
        $model->syncPermissions($data['permissions'] ?? []);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('forms.saved')]);

        return back();
    }
}
