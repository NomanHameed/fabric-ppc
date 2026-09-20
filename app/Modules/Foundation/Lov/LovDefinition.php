<?php

namespace App\Modules\Foundation\Lov;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Optional hooks, checked with `method_exists` by LovController:
 *
 *  - `applyExact(Builder $query, string $value): void` replaces the default exact match on exactColumn()
 *    (PartyLov resolves aliases; LotLov accepts the 4-digit floor number).
 *  - `applySearch(Builder $query, string $needle): void` replaces the default `LIKE '%needle%'` across
 *    searchColumns() (PartyLov normalises the needle the way party names are stored).
 */
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
