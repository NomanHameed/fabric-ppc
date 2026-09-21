<?php

namespace App\Modules\Production\Policies;

use App\Models\User;
use App\Modules\Production\Models\ProductionSheet;

/**
 * Spec §8: PPC and Manager (`production.sheet.edit.all`) edit every sheet; a Department In-charge
 * (`production.sheet.edit.own_department`) only sheets of the departments on their `department_user` rows.
 * Viewing needs `production.sheet.view`. The admin bypass is the Gate::before in AppServiceProvider.
 */
final class ProductionSheetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('production.sheet.view');
    }

    public function view(User $user, ProductionSheet $sheet): bool
    {
        return $user->can('production.sheet.view');
    }

    /** Creating a sheet for $departmentId (the header's department). */
    public function create(User $user, int $departmentId): bool
    {
        return $this->canEditDepartment($user, $departmentId);
    }

    public function update(User $user, ProductionSheet $sheet): bool
    {
        return $this->canEditDepartment($user, $sheet->department_id);
    }

    public function delete(User $user, ProductionSheet $sheet): bool
    {
        return $this->canEditDepartment($user, $sheet->department_id);
    }

    private function canEditDepartment(User $user, int $departmentId): bool
    {
        if ($user->can('production.sheet.edit.all')) {
            return true;
        }

        return $user->can('production.sheet.edit.own_department') && $user->departments()->whereKey($departmentId)->exists();
    }
}
