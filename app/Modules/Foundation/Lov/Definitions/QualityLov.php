<?php

namespace App\Modules\Foundation\Lov\Definitions;

use App\Modules\Foundation\Lov\LovDefinition;
use App\Modules\Foundation\Models\Quality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class QualityLov implements LovDefinition
{
    public function name(): string { return 'qualities'; }

    public function permission(): ?string { return null; }

    public function columns(): array
    {
        return [['key' => 'code', 'label' => __('masters.fields.oracle_code')], ['key' => 'label', 'label' => __('masters.fields.description')]];
    }

    public function query(array $filters): Builder { return Quality::query()->where('is_active', true); }

    public function searchColumns(): array { return ['description']; }

    public function exactColumn(): string { return 'oracle_code'; }

    public function row(Model $m): array { return ['id' => $m->id, 'code' => (string) $m->oracle_code, 'label' => $m->description]; }
}
