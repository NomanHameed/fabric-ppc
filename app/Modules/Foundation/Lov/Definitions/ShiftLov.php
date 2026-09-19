<?php

namespace App\Modules\Foundation\Lov\Definitions;

use App\Modules\Foundation\Lov\LovDefinition;
use App\Modules\Foundation\Models\Shift;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ShiftLov implements LovDefinition
{
    public function name(): string { return 'shifts'; }

    public function permission(): ?string { return null; }

    public function columns(): array
    {
        return [['key' => 'code', 'label' => __('masters.fields.code')], ['key' => 'label', 'label' => __('masters.fields.name')]];
    }

    public function query(array $filters): Builder { return Shift::query(); }

    public function searchColumns(): array { return ['code', 'name']; }

    public function exactColumn(): string { return 'code'; }

    public function row(Model $m): array { return ['id' => $m->id, 'code' => $m->code, 'label' => $m->name]; }
}
