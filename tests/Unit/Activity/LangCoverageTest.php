<?php

/*
 * Every action key and timeline event type must have a line in BOTH lang/en and lang/ur. A missing line makes
 * ActionLogger store the bare key as the description — a bare string on screen, which the project forbids —
 * and a missing Urdu line falls back to English silently.
 */

use App\Modules\Activity\Support\ActionKeys;
use App\Modules\Production\Timeline\TimelineEventType;

/** @return array<string, mixed> */
function langFile(string $locale, string $group): array
{
    return require dirname(__DIR__, 3)."/lang/{$locale}/{$group}.php";
}

function langHas(string $locale, string $group, string $key): bool
{
    $value = langFile($locale, $group);
    foreach (explode('.', $key) as $segment) {
        if (! is_array($value) || ! array_key_exists($segment, $value)) {
            return false;
        }
        $value = $value[$segment];
    }

    return is_string($value) && $value !== '';
}

it('has an en and an ur line for every action key', function (string $action) {
    expect(langHas('en', 'activity', 'actions.'.$action))->toBeTrue("lang/en/activity.php actions.{$action}")
        ->and(langHas('ur', 'activity', 'actions.'.$action))->toBeTrue("lang/ur/activity.php actions.{$action}");
})->with(ActionKeys::ALL);

it('has an en and an ur line for every timeline event type', function (string $type) {
    expect(langHas('en', 'timeline', 'events.'.$type))->toBeTrue("lang/en/timeline.php events.{$type}")
        ->and(langHas('ur', 'timeline', 'events.'.$type))->toBeTrue("lang/ur/timeline.php events.{$type}");
})->with(TimelineEventType::ALL);

it('keeps the activity, timeline and approval groups identical between en and ur', function (string $group) {
    $flatten = function (array $values, string $prefix = '') use (&$flatten): array {
        $keys = [];
        foreach ($values as $key => $value) {
            $dotted = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $keys = is_array($value) ? [...$keys, ...$flatten($value, $dotted)] : [...$keys, $dotted];
        }
        sort($keys);

        return $keys;
    };

    expect($flatten(langFile('ur', $group)))->toBe($flatten(langFile('en', $group)));
})->with(['activity', 'timeline', 'approval', 'masters']);
