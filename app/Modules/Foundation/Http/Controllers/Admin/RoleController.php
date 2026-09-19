<?php

namespace App\Modules\Foundation\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Access\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
        $model = Role::findByName($role, 'web');
        $data = $request->validate([
            'permissions' => ['array'],
            'permissions.*' => [Rule::in(Permissions::ALL)],
        ]);
        $model->syncPermissions($data['permissions'] ?? []);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('forms.saved')]);

        return back();
    }
}
