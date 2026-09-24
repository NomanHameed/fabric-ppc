<?php

/*
 * EN and UR must carry exactly the same keys. A key present in one file and missing from the other silently
 * falls back to English at runtime, so nothing fails and the gap only shows up on screen — this freezes the
 * two groups the Production screens read from (`production`, `app`).
 */

/**
 * Every leaf key of a lang array, in dotted form.
 *
 * @param  array<string, mixed>  $values
 * @return list<string>
 */
function langKeys(array $values, string $prefix = ''): array
{
    $keys = [];
    foreach ($values as $key => $value) {
        $dotted = $prefix === '' ? (string) $key : $prefix.'.'.$key;
        if (is_array($value)) {
            $keys = [...$keys, ...langKeys($value, $dotted)];

            continue;
        }
        $keys[] = $dotted;
    }

    return $keys;
}

/** @return list<string> */
function langGroup(string $locale, string $group): array
{
    $keys = langKeys(require dirname(__DIR__, 3)."/lang/{$locale}/{$group}.php");
    sort($keys);

    return $keys;
}

it('keeps the ur keys of the production and app groups identical to the en ones', function (string $group) {
    $en = langGroup('en', $group);
    $ur = langGroup('ur', $group);

    expect(array_values(array_diff($en, $ur)))->toBe([], "keys missing from lang/ur/{$group}.php")
        ->and(array_values(array_diff($ur, $en)))->toBe([], "keys in lang/ur/{$group}.php with no en counterpart")
        ->and($ur)->toBe($en);
})->with(['production', 'app']);
