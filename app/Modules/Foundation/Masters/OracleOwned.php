<?php

namespace App\Modules\Foundation\Masters;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Master data Oracle owns: rows the mill maintains in its ERP, which this application only mirrors.
 *
 * The client's rule (and the reason this class exists): a master whose rows arrive from the Oracle import
 * must stay query-only on its own screen. The screen itself is kept — browse, F7/F8 and record navigation
 * all still work — but New / Save / Delete are off, and a crafted POST/PUT/DELETE is refused server-side
 * too, so the UI and the refusal cannot drift apart: both read the one declaration below.
 *
 * What this class deliberately does *not* do is enforce anything on the model. The Oracle import is the
 * legitimate writer of exactly these rows (`PartyResolver::create()`/`link()`, `Quality::fromOracle()`,
 * and the `oracle_code` back-fill in `ImportCommitService`), and a model-level guard would break it. The
 * guard therefore lives at the HTTP boundary — the master screens — which is the only place a user keys
 * data in by hand.
 *
 * Adding a master is one line in `MASTERS`. Note what is *not* there and why:
 *
 *  - `machines` / `operations` — Oracle has `ERP.COST_CENTRES` and `ERP.PROCESSING_OPERATIONS`, and these
 *    two are Oracle-sourced in principle, but in this codebase they have no `oracle_code` column and no
 *    importer write path at all. Their master screen is the only way a machine or an operation can ever be
 *    created or corrected, so locking them would strand them permanently. They belong here only once a
 *    sync exists.
 *  - `stage_processes` — carries a copy of Oracle's production line (`*_oracle` columns) but is an
 *    app-owned record of what the floor did, not a mirror of an Oracle table.
 */
final class OracleOwned
{
    /**
     * Oracle-owned masters, mapped to the fields the *app* still owns on them.
     *
     * The key is the master key: `MasterResource::key()` for the resources that go through
     * `MasterController`, and the same screen key for the bespoke screens that do not (`parties`).
     *
     * An empty list means Oracle owns the whole row — nothing on the screen can be saved. A non-empty list
     * means the screen keeps Save for exactly those fields; everything else is read-only and a request that
     * changes one is refused.
     *
     * Per-field reasoning:
     *
     *  - `qualities` => ['is_active'] — `oracle_code` and `description` are Oracle's
     *    (`Quality::fromOracle()`), and every other column (`ends`, `picks`, `composition`, …) is parsed out
     *    of that description, so Oracle owns the row's substance. `is_active` is the one field the importer
     *    never writes: it only hides a quality from the LOVs. The live instance has 3,035 qualities, 672 of
     *    them with no description at all, and only 117 of the 149 codes the processing rows use resolve to a
     *    name — so without this flag nothing in the app can prune that LOV. The client confirmed they want
     *    to keep deactivating obsolete qualities, which also makes this consistent with `parties.is_active`.
     *  - `parties` => [...] — Oracle owns a party's identity: `oracle_code` (the mapping the import screen's
     *    resolve/link flow sets) and `name` (what the export calls them). The app owns the rest: `type`
     *    (brand/local is this application's classification — the user picks it in the resolve-party dialog,
     *    nothing in Oracle supplies it), `is_active` (LOV visibility only) and `aliases` (how the floor's
     *    local shorthand maps onto an Oracle party — the unresolved-party resolution flow depends on these
     *    staying editable).
     */
    private const MASTERS = [
        'qualities' => ['is_active'],
        'parties' => ['type', 'is_active', 'aliases'],
    ];

    public static function owns(string $master): bool
    {
        return array_key_exists($master, self::MASTERS);
    }

    /**
     * The fields a user may still key in; empty for an Oracle-owned master the app owns nothing on.
     *
     * @return list<string>
     */
    public static function appOwnedFields(string $master): array
    {
        return self::MASTERS[$master] ?? [];
    }

    /**
     * What a screen needs to grey out its own write actions. Shipped with the page props so the UI and the
     * server-side refusal come from this one declaration.
     *
     * @return array{oracleOwned: bool, appOwnedFields: list<string>}
     */
    public static function meta(string $master): array
    {
        return [
            'oracleOwned' => self::owns($master),
            'appOwnedFields' => self::appOwnedFields($master),
        ];
    }

    /**
     * Only the Oracle import creates these rows.
     *
     * @throws ValidationException
     */
    public static function assertCanCreate(string $master): void
    {
        if (self::owns($master)) {
            self::refuse(__('masters.oracle_owned.cannot_create'));
        }
    }

    /**
     * Only Oracle (and the import that follows it) removes these rows.
     *
     * @throws ValidationException
     */
    public static function assertCanDelete(string $master): void
    {
        if (self::owns($master)) {
            self::refuse(__('masters.oracle_owned.cannot_delete'));
        }
    }

    /**
     * Refuse an update that would change Oracle-owned data, and return the data stripped down to the fields
     * the app owns.
     *
     * A disabled input still posts its current value, so the normal save of an app-owned field (a party's
     * aliases) arrives with the Oracle-owned fields unchanged — that is allowed. A crafted request that
     * sends a *different* value for one is refused outright. The returned data carries the submitted value
     * for every app-owned field and the record's **stored** value for every Oracle-owned one, so the payload
     * stays complete for the resource's fill() while no Oracle column can be altered by the request.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function assertCanUpdate(string $master, Model $record, array $data): array
    {
        if (! self::owns($master)) {
            return $data;
        }
        $appOwned = self::appOwnedFields($master);
        if ($appOwned === []) {
            self::refuse(__('masters.oracle_owned.cannot_update'));
        }
        foreach (array_keys($data) as $field) {
            if (in_array($field, $appOwned, true)) {
                continue;
            }
            if (! self::same($data[$field], $record->getAttribute($field))) {
                self::refuse(__('masters.oracle_owned.cannot_update'));
            }
            // Hand back Oracle's *stored* value rather than dropping the key. A resource's fill() may require
            // the field — QualityResource re-parses `description` into ends/picks/composition — so returning
            // only the app-owned keys made it fail on an undefined index. Substituting the stored value also
            // closes the gap left by the loose comparison above: `"394"` compares equal to 394 but it is the
            // integer that gets written, so no Oracle column is ever rewritten in a different shape.
            $data[$field] = $record->getAttribute($field);
        }

        return $data;
    }

    /**
     * Compare a submitted value with the stored one loosely on purpose: a form posts `"394"` for an integer
     * column and `""` for an empty one, and a field the request left out arrives here as null. Anything that
     * means the same value must count as unchanged, or saving a party's aliases would be refused.
     */
    private static function same(mixed $submitted, mixed $stored): bool
    {
        $normalise = static fn (mixed $v): ?string => match (true) {
            $v === null, $v === '' => null,
            is_bool($v) => $v ? '1' : '0',
            is_scalar($v) => (string) $v,
            // Not reachable through a validated master screen; serialize keeps the comparison total anyway.
            default => serialize($v),
        };

        return $normalise($submitted) === $normalise($stored);
    }

    /**
     * Refusals come back the way this codebase returns a refused write: a validation error on `record`, the
     * same key `MasterController::destroy` uses for `forms.in_use`. The master screens already render it
     * (`form.errors['record']`) and push it onto the status line, and a delete passes it through
     * `form.setError()`.
     *
     * @throws ValidationException
     */
    private static function refuse(string $message): never
    {
        throw ValidationException::withMessages(['record' => $message]);
    }
}
