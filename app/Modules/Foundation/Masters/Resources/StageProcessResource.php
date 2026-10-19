<?php

namespace App\Modules\Foundation\Masters\Resources;

use App\Modules\Foundation\Masters\Field;
use App\Modules\Foundation\Masters\MasterResource;
use App\Modules\Foundation\Models\Stage;
use App\Modules\Foundation\Models\StageProcess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * The Stage Processes master: one Oracle production line per row, linked to its reporting stage.
 *
 * **Deliberately restructured by the user** (migration `2026_10_13_000100`). The screen used to edit the
 * process-name taxonomy — code, name, sort order, quantity type, the three `is_*` flags — and those columns
 * no longer exist. It now edits the twenty Oracle fields the mill works from: party, PO, reference, design,
 * colour, colour status, PO type, PO date, lot number, quality, greige width, status and the six quantities.
 *
 * **Identity.** `unique(stage_id, code)` is gone with `code`. Its replacement is
 * `unique(stage_id, po_no, lot_no_oracle)` — a lot number alone is not unique even on `lots`, because the
 * mill restarts lot numbers per PO, so the PO has to be part of the key. The rule is only applied when both
 * the PO and the lot number were entered: nothing imports into this table yet, so a half-filled row must
 * still be saveable, and the database index agrees (both MySQL and SQLite treat NULLs as distinct).
 */
class StageProcessResource extends MasterResource
{
    /** Validation for `decimal(12,2)`: the widest value the column can hold. */
    private const MAX_QUANTITY = '9999999999.99';

    /** The one date format the mill's screens show and take. */
    private const DATE_FORMAT = 'd-m-Y';

    public function key(): string
    {
        return 'stage_processes';
    }

    public function model(): string
    {
        return StageProcess::class;
    }

    public function with(): array
    {
        return ['stage'];
    }

    /**
     * Grouped by the stage the line is filed under; `MasterController` breaks the ties on `id`, so several
     * lines under one stage keep the order they were entered in (the order `Stage::processes()` uses too)
     * and the screen's record walk lands on the same row every load.
     */
    public function defaultSort(): string
    {
        return 'stage_id';
    }

    /** @return list<Field> */
    public function fields(): array
    {
        return [
            // The link to the stage, and the three fields that name the line on the floor.
            Field::make('stage_id', 'select')->label('masters.fields.stage')->required()
                ->options(self::stageOptions())->queryType('number')->span(3),
            Field::make('lot_no_oracle')->span(2),
            Field::make('po_no', 'number')->span(2),
            Field::make('ref')->span(2),
            // `datetime`, not the default `text`: the column is a DATETIME, so a text criterion would compare
            // `LOWER(date_oracle)` against `2026-09-18 00:00:00` and find nothing. `datetime` compares the
            // date part (`whereDate`) and accepts `d-m-Y`, `d/m/Y` or `Y-m-d` from the operator.
            Field::make('date_oracle')->queryType('datetime')->span(3),
            // The party, and the quality the report prints.
            Field::make('party_oracle_id', 'number')->span(2),
            Field::make('party_oracle_name')->span(5),
            Field::make('quality')->span(5)->grid(false),
            // Design, colour and the two Oracle classifications.
            Field::make('design_oracle_id')->span(2),
            Field::make('color_oracle_id')->span(2),
            Field::make('color_oracle_name')->span(4),
            Field::make('clr_status_oracle')->span(2),
            Field::make('po_type_oracle')->span(2),
            // The raw status and the greige width.
            Field::make('status_oracle')->span(2),
            Field::make('gw_oracle', 'number')->span(2),
            // The six quantities, in the order the Month Wise Fabric Report prints them.
            Field::make('greige_received_oracle', 'number')->span(2),
            Field::make('greige_in_stock_oracle', 'number')->span(2),
            Field::make('qty_issued', 'number')->span(2),
            Field::make('wip', 'number')->span(2),
            Field::make('finished', 'number')->span(2),
            Field::make('dispatch', 'number')->span(2),
        ];
    }

    /**
     * The PO date as `d-m-Y`, so the field shows a date and not the cast's full timestamp.
     *
     * `d-m-Y` is the only date format the mill's screens use — the contract and lot screens, the inquiries,
     * the flat lot rows and the Production Sheet's own editable date all show and take it — so the one
     * editable date on this screen does the same, and `fill()` parses it back.
     *
     * @return array<string, mixed>
     */
    public function toRow(Model $m): array
    {
        $date = $m instanceof StageProcess ? $m->date_oracle : null;

        return array_merge(parent::toRow($m), ['date_oracle' => $date?->format(self::DATE_FORMAT)]);
    }

    /**
     * Persist the row, turning the typed `d-m-Y` PO date back into a value the DATETIME column takes.
     *
     * `createFromFormat('!d-m-Y', …)` — the leading `!` zeroes the time, exactly as
     * `ProductionSheetController::date()` does it — so every row here holds the PO date at midnight, like
     * every other `date_oracle` in the database. Without it the current time of day would leak in and a
     * `whereDate` range boundary would behave differently for this row than for its neighbours.
     *
     * @param  array<string, mixed>  $data
     */
    public function fill(Model $m, array $data): void
    {
        if (array_key_exists('date_oracle', $data)) {
            $value = $data['date_oracle'];
            $data['date_oracle'] = $value === null || $value === ''
                ? null
                : CarbonImmutable::createFromFormat('!'.self::DATE_FORMAT, (string) $value);
        }

        parent::fill($m, $data);
    }

    /** @return array<string, list<mixed>> */
    public function rules(?Model $record): array
    {
        $rules = [
            'stage_id' => ['required', 'integer', 'exists:stages,id'],
            'party_oracle_id' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'party_oracle_name' => ['nullable', 'string', 'max:150'],
            'po_no' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'ref' => ['nullable', 'string', 'max:60'],
            'design_oracle_id' => ['nullable', 'string', 'max:20'],
            'color_oracle_id' => ['nullable', 'string', 'max:20'],
            'color_oracle_name' => ['nullable', 'string', 'max:80'],
            'clr_status_oracle' => ['nullable', 'string', 'max:40'],
            'po_type_oracle' => ['nullable', 'string', 'max:40'],
            // `date_format`, not the loose `date`: `date` would also accept `2026-09-18 14:30` and store a
            // time of day in a column every other row holds at midnight.
            'date_oracle' => ['nullable', 'date_format:'.self::DATE_FORMAT],
            'lot_no_oracle' => ['nullable', 'string', 'max:10'],
            'quality' => ['nullable', 'string', 'max:255'],
            // `decimal:0,2` as well as the bounds: `decimal(6,2)` cannot hold a third decimal place, and
            // without the rule MySQL would silently round it while SQLite kept it (the Issue to Floor and
            // Production Sheet quantities are guarded the same way).
            'gw_oracle' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999.99'],
            'status_oracle' => ['nullable', 'string', 'max:40'],
        ];

        // `decimal:0,2`: the columns are `decimal(12,2)`, so a third decimal place is not a quantity the
        // mill can store — it must come back as a validation error, not be rounded away behind the operator.
        foreach (StageProcess::QUANTITY_COLUMNS as $column) {
            $rules[$column] = ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::MAX_QUANTITY];
        }

        // The replacement for `unique(stage_id, code)`, mirroring the database index exactly: it only claims
        // a row that names both a PO and a lot number.
        if (self::filled('po_no') && self::filled('lot_no_oracle')) {
            $rules['lot_no_oracle'][] = Rule::unique('stage_processes', 'lot_no_oracle')
                ->where('stage_id', request('stage_id'))
                ->where('po_no', request('po_no'))
                ->ignore($record?->getKey());
        }

        return $rules;
    }

    /** True when the request carries a non-empty value for the field. */
    private static function filled(string $field): bool
    {
        $value = request($field);

        return $value !== null && $value !== '';
    }

    /**
     * Every stage, in its own order, as the select's options.
     *
     * @return list<array{value: int, label: string}>
     */
    private static function stageOptions(): array
    {
        $options = [];
        foreach (Stage::query()->orderBy('sort_order')->orderBy('code')->get() as $stage) {
            $options[] = ['value' => (int) $stage->getKey(), 'label' => (string) $stage->getAttribute('name')];
        }

        return $options;
    }
}
