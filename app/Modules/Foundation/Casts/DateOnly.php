<?php

namespace App\Modules\Foundation\Casts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A true DATE column: stored as `Y-m-d`, read back as a start-of-day `CarbonImmutable`.
 *
 * **Do not "simplify" this back to `'date'`.** Laravel's built-in `date` cast only controls
 * *serialization*; on the way *in* it writes through `Model::fromDateTime()`, which formats with the
 * connection grammar's full `Y-m-d H:i:s`. So Eloquent sends `2026-09-11 00:00:00` for a DATE column,
 * and the two drivers this app runs on then disagree:
 *
 * - **MySQL** (production) has a real DATE type, so it silently truncates the time part and stores
 *   `2026-09-11`.
 * - **SQLite** (`phpunit.xml`, the test suite) is dynamically typed and stores the string verbatim,
 *   time part and all.
 *
 * That divergence makes the test environment lie about production behaviour. The visible symptom is
 * `QueryCriteria`'s (`App\Modules\Foundation\Query\QueryCriteria`) `date` criterion type, which compares a
 * DATE column against a plain `Y-m-d` with `where($column, '=', …)` — on purpose, so the composite and
 * single-column indexes over these columns stay usable in a report-heavy app, where `whereDate()` would
 * wrap the column in a function and lose them. Against a SQLite row holding `2026-09-11 00:00:00` that
 * equality never matches, and the same goes for every hand-written `where('sheet_date', 'Y-m-d')`
 * uniqueness probe and every raw seeder insert that writes `Y-m-d`.
 *
 * Fixing it here, at the cast, means both drivers store the identical value and every one of those
 * comparisons behaves the same everywhere. Applied to `contracts.po_date`, `production_sheets.sheet_date`
 * and `machine_allocations.allocated_on` — the schema's only three DATE columns.
 *
 * @implements CastsAttributes<CarbonImmutable, DateTimeInterface|string|null>
 */
final class DateOnly implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        return $value === null || $value === '' ? null : self::toCarbon($value)->startOfDay();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null || $value === '' ? null : self::toCarbon($value)->toDateString();
    }

    private static function toCarbon(mixed $value): CarbonImmutable
    {
        return $value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse((string) $value);
    }
}
