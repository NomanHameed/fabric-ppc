<?php

namespace App\Modules\Production\Lov;

use App\Modules\Foundation\Lov\LovDefinition;
use App\Modules\Foundation\Models\RouteTemplate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** Active route templates, for the Lot Route editor's "apply template" pick (spec §6.2 planning). */
class RouteTemplateLov implements LovDefinition
{
    public function name(): string
    {
        return 'route-templates';
    }

    public function permission(): ?string
    {
        return null;
    }

    public function columns(): array
    {
        return [
            ['key' => 'label', 'label' => __('masters.fields.name')],
            ['key' => 'po_type', 'label' => __('masters.fields.po_type')],
            ['key' => 'quality_pattern', 'label' => __('masters.fields.quality_pattern')],
            ['key' => 'steps', 'label' => __('masters.fields.steps')],
        ];
    }

    /** @return Builder<RouteTemplate> */
    public function query(array $filters): Builder
    {
        return RouteTemplate::query()->withCount('steps')->where('is_active', true);
    }

    public function searchColumns(): array
    {
        return ['name', 'po_type'];
    }

    public function exactColumn(): string
    {
        return 'name';
    }

    public function row(Model $m): array
    {
        if (! $m instanceof RouteTemplate) {
            throw new InvalidArgumentException('RouteTemplateLov rows are route templates.');
        }

        return [
            'id' => $m->id, 'code' => $m->name, 'label' => $m->name, 'po_type' => $m->po_type,
            'quality_pattern' => $m->quality_pattern, 'steps' => (int) $m->steps_count,
        ];
    }
}
