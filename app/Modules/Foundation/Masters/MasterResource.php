<?php

namespace App\Modules\Foundation\Masters;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

abstract class MasterResource
{
    abstract public function key(): string;

    /** @return class-string<Model> */
    abstract public function model(): string;

    /** @return list<Field> */
    abstract public function fields(): array;

    abstract public function rules(?Model $record): array;

    public function titleKey(): string
    {
        return 'masters.titles.'.$this->key();
    }

    /** @return list<string> */
    public function with(): array
    {
        return [];
    }

    /**
     * The column the screen's rows are ordered by. `MasterController::index` always appends the primary key
     * as a tie-breaker, so a sort column with repeats still gives a total, repeatable order.
     */
    public function defaultSort(): string
    {
        return 'id';
    }

    public function rowLimit(): int
    {
        return 2000;
    }

    public function viewPermission(): string
    {
        return 'masters.view';
    }

    public function editPermission(): string
    {
        return 'masters.manage';
    }

    public function toRow(Model $m): array
    {
        return ['id' => $m->getKey()] + collect($this->fields())
            ->reject(fn (Field $f) => $f->type === 'multiselect')
            ->mapWithKeys(fn (Field $f) => [$f->name => $m->getAttribute($f->name)])
            ->all();
    }

    /** Persist scalar attributes; override for mapped/virtual fields. */
    public function fill(Model $m, array $data): void
    {
        $scalar = collect($this->fields())->reject(fn (Field $f) => $f->type === 'multiselect')->map->name->all();
        $m->fill(array_intersect_key($data, array_flip($scalar)));
    }

    /** Relations (pivots) after save. */
    public function afterSave(Model $m, array $data): void {}

    /**
     * Refuse a delete the database itself would allow.
     *
     * A foreign key already stops a delete that would orphan rows (MasterController maps that violation to
     * `forms.in_use`), but a nullable `nullOnDelete` reference does not: the delete succeeds and quietly
     * empties a column on rows nobody meant to touch. A resource whose references are of that kind says so
     * here by throwing a ValidationException on `record`, exactly as the FK path does.
     *
     * @throws ValidationException
     */
    public function assertDeletable(Model $m): void {}

    /** @return array<string, array{column: string, type: string}> */
    public function queryFields(): array
    {
        return collect($this->fields())->filter(fn (Field $f) => $f->queryable() !== null)
            ->mapWithKeys(fn (Field $f) => [$f->name => ['column' => $f->name, 'type' => $f->queryable()]])
            ->all();
    }

    /**
     * `oracleOwned` / `appOwnedFields` come straight from `OracleOwned` so the screen greys out exactly the
     * write actions the controller refuses (see that class).
     */
    public function meta(bool $canEdit): array
    {
        return [
            'key' => $this->key(), 'title' => __($this->titleKey()), 'canEdit' => $canEdit,
            'fields' => array_map(fn (Field $f) => $f->toArray(), $this->fields()),
        ] + OracleOwned::meta($this->key());
    }
}
