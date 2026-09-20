<?php

namespace App\Modules\Foundation\Query;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Validation\ValidationException;

final class QueryCriteria
{
    private const OPERATORS = ['>=', '<=', '!=', '>', '<', '='];

    private const DATE_FORMATS = ['d-m-Y', 'd/m/Y', 'Y-m-d'];

    /**
     * @param  array<string, mixed>  $criteria  raw query input; an array value (`q[field][]=…`) is a validation error (M-5)
     * @param  array<string, array{column: string, type: string}>  $fields
     */
    public static function apply(Builder $query, array $criteria, array $fields): Builder
    {
        foreach ($criteria as $key => $raw) {
            if (is_array($raw)) {
                throw ValidationException::withMessages([$key => __('forms.invalid_criteria')]);
            }
            $value = trim((string) $raw);
            if ($value === '' || ! isset($fields[$key])) {
                continue;
            }
            ['column' => $column, 'type' => $type] = $fields[$key];

            if (strtoupper($value) === '#NULL') {
                $query->whereNull($column);

                continue;
            }

            match ($type) {
                'text' => self::text($query, $column, $value),
                'bool' => $query->where($column, in_array(strtolower($value), ['y', '1', 'true', 'yes'], true)),
                'number', 'date' => self::comparable($query, $column, $value, $type, $key),
            };
        }

        return $query;
    }

    private static function text(Builder $query, string $column, string $value): void
    {
        $operator = str_contains($value, '%') || str_contains($value, '_') ? 'like' : '=';
        $query->whereRaw("LOWER({$column}) {$operator} ?", [mb_strtolower($value)]);
    }

    private static function comparable(Builder $query, string $column, string $value, string $type, string $key): void
    {
        if (str_contains($value, '..')) {
            [$from, $to] = array_map('trim', explode('..', $value, 2));
            $query->whereBetween($column, [self::cast($from, $type, $key), self::cast($to, $type, $key)]);

            return;
        }

        $operator = '=';
        foreach (self::OPERATORS as $op) {
            if (str_starts_with($value, $op)) {
                $operator = $op;
                $value = trim(substr($value, strlen($op)));
                break;
            }
        }
        $query->where($column, $operator === '!=' ? '<>' : $operator, self::cast($value, $type, $key));
    }

    private static function cast(string $value, string $type, string $key): string|float
    {
        if ($type === 'number') {
            if (! is_numeric($value)) {
                throw ValidationException::withMessages([$key => __('forms.invalid_criteria')]);
            }

            return (float) $value;
        }

        foreach (self::DATE_FORMATS as $format) {
            try {
                $date = CarbonImmutable::createFromFormat('!'.$format, $value);
                if ($date !== false && $date->format($format) === $value) {
                    return $date->toDateString();
                }
            } catch (InvalidFormatException) {
                continue;
            }
        }

        throw ValidationException::withMessages([$key => __('forms.invalid_criteria')]);
    }
}
