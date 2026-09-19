<?php

namespace App\Modules\Foundation\Masters\Resources;

use App\Modules\Foundation\Masters\Field;
use App\Modules\Foundation\Masters\MasterResource;
use App\Modules\Foundation\Models\Quality;
use App\Modules\Foundation\Support\QualityParser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class QualityResource extends MasterResource
{
    public function key(): string { return 'qualities'; }

    public function model(): string { return Quality::class; }

    public function defaultSort(): string { return 'oracle_code'; }

    public function fields(): array
    {
        return [
            Field::make('oracle_code', 'number')->span(2),
            Field::make('description')->required()->span(8),
            Field::make('is_active', 'bool')->span(2),
            Field::make('ends', 'number')->readonly()->span(1),
            Field::make('picks', 'number')->readonly()->span(1),
            Field::make('warp_count')->readonly()->span(2),
            Field::make('weft_count')->readonly()->span(2),
            Field::make('composition')->readonly()->span(3),
            Field::make('loom')->readonly()->span(1),
            Field::make('notes')->readonly()->span(2),
        ];
    }

    public function rules(?Model $record): array
    {
        return [
            'oracle_code' => ['nullable', 'integer', Rule::unique('qualities', 'oracle_code')->ignore($record?->getKey())],
            'description' => ['required', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }

    public function fill(Model $m, array $data): void
    {
        $p = QualityParser::parse($data['description']);
        $m->fill([
            'oracle_code' => $data['oracle_code'] ?? null, 'description' => trim($data['description']),
            'is_active' => $data['is_active'] ?? true,
            'ends' => $p->ends, 'picks' => $p->picks, 'warp_count' => $p->warpCount, 'weft_count' => $p->weftCount,
            'composition' => $p->composition, 'loom' => $p->loom, 'notes' => $p->notes,
        ]);
    }
}
