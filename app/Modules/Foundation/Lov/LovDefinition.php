<?php

namespace App\Modules\Foundation\Lov;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

interface LovDefinition
{
    public function name(): string;

    /** Permission required, or null for any authenticated user. */
    public function permission(): ?string;

    /** @return list<array{key: string, label: string}> */
    public function columns(): array;

    /** @param array<string, string> $filters */
    public function query(array $filters): Builder;

    /** @return list<string> */
    public function searchColumns(): array;

    public function exactColumn(): string;

    /** @return array{id: int, code: string, label: string} */
    public function row(Model $model): array;
}
