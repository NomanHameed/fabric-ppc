<?php

namespace App\Modules\Foundation\Lov\Definitions;

use App\Models\User;
use App\Modules\Foundation\Lov\LovDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class UserLov implements LovDefinition
{
    public function name(): string { return 'users'; }

    public function permission(): ?string { return null; }

    public function columns(): array
    {
        return [['key' => 'label', 'label' => __('masters.fields.name')], ['key' => 'code', 'label' => __('masters.fields.email')]];
    }

    public function query(array $filters): Builder { return User::query()->where('is_active', true); }

    public function searchColumns(): array { return ['name', 'email']; }

    public function exactColumn(): string { return 'email'; }

    public function row(Model $m): array { return ['id' => $m->id, 'code' => $m->email, 'label' => $m->name]; }
}
