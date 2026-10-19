<?php

namespace App\Modules\Production\Status;

use App\Modules\Foundation\Models\Stage;
use Closure;

/**
 * Raw status (`lots.status_oracle`) -> reporting stage, from the `stages.status_aliases` column. Nothing here
 * hard-codes a status string: the mapping is master data, so a status the mill starts writing tomorrow is
 * mapped on the Stages master, not in a deploy.
 *
 * **Stage-only.** A lot carries one stage and no stage process (`lots.stage_process_id` is gone), so the
 * report has one grouping level and this class answers one question. The `stage_processes` table now holds
 * the mill's Oracle production lines (migration `2026_10_13_000100`) and has no aliases at all, so nothing
 * resolves to it and this class never reads it.
 *
 * **Normalisation** (spec §8), applied to the status *and* to every alias before they are compared — the
 * stored status itself is never changed:
 *
 *   1. leading and trailing whitespace removed;
 *   2. every run of whitespace inside collapsed to one space;
 *   3. trailing `/` and spaces removed (the tracker writes `DYEING/`, `DYEING /` and `DYEING` for one thing);
 *   4. case folded to upper case.
 *
 * So `DYEING`, `dyeing`, `DYEING/` and `DYEING  / ` all resolve to the same stage, while the slash *inside*
 * `SANFO/CAL` is kept — only a trailing one is punctuation.
 *
 * **Matching**, in this order:
 *
 *   1. a blank status (null, empty, or nothing left after normalisation) resolves to whichever stage claims
 *      the sentinel alias `#BLANK` — the Greige stage, where a lot that has never been issued belongs. The
 *      labeller leaves the status empty while everything is still in stock (rule 6), and before this those
 *      lots fell out of the report into `unclassified`;
 *   2. an exact alias;
 *   3. a wildcard alias (an alias containing `*`, matched as `.*`), most specific first — "most specific" is
 *      the alias with the most literal (non-wildcard) characters, ties going to whichever stage comes first
 *      in stage order. This is how the dispatch family (`DISP 11-09-26/` -> `DISP *`) and "anything
 *      containing CANCEL" (`*CANCEL*`) are expressed without one alias per date;
 *   4. nothing: the stage comes back null. An unknown status is reported by the Unmapped Statuses screen and
 *      never guessed (a wrong stage is worse in a report than an empty one).
 *
 * `#BLANK` is deliberately not reachable as a real status: it is matched before normalisation can produce it
 * and no mill status starts with `#`. A stage that does not claim it changes nothing.
 *
 * The map is read once and memoised, so a backfill over every lot is one query, not one per lot. It is
 * registered per request (`scoped`) and flushed whenever a stage is saved or deleted.
 */
final class StatusStageResolver
{
    /** @var array{stage_id: null} */
    private const UNRESOLVED = ['stage_id' => null];

    /**
     * exact: normalised alias => ids; wildcard: the same, as anchored regexes, most specific first; blank:
     * the stage that claims a lot with no status at all.
     *
     * @var array{exact: array<string, array{stage_id: int}>, wildcard: list<array{regex: string, ids: array{stage_id: int}}>, blank: array{stage_id: int}|null}|null
     */
    private ?array $map = null;

    /**
     * @param  (Closure(): iterable<array{stage_id: int, aliases: list<string>}>)|null  $loader
     *                                                                                           the alias rows, in stage order; the default reads the active stages from the database. A test
     *                                                                                           passes its own literal rows, so the normalisation and matching rules are exercised with no
     *                                                                                           database and no Eloquent at all.
     */
    public function __construct(private readonly ?Closure $loader = null) {}

    /**
     * The stage a raw status means, or null when nothing claims it.
     *
     * @return array{stage_id: int|null}
     */
    public function resolve(?string $status): array
    {
        $map = $this->map();
        $needle = self::normalise($status);
        if ($needle === null) {
            return $map['blank'] ?? self::UNRESOLVED;
        }

        if (isset($map['exact'][$needle])) {
            return $map['exact'][$needle];
        }
        foreach ($map['wildcard'] as $candidate) {
            if (preg_match($candidate['regex'], $needle) === 1) {
                return $candidate['ids'];
            }
        }

        return self::UNRESOLVED;
    }

    /** The stage id a status means, or null. */
    public function stageId(?string $status): ?int
    {
        return $this->resolve($status)['stage_id'];
    }

    /**
     * Whether some stage claims this status.
     *
     * A blank status counts as mapped only when a stage actually claims `#BLANK`: before Greige was seeded,
     * "no status" said nothing and was not a mapping gap, and the Unmapped Statuses screen still never lists
     * it (it reads only lots whose status is not null).
     */
    public function isMapped(?string $status): bool
    {
        return $this->resolve($status)['stage_id'] !== null;
    }

    /** Drop the memoised map; the next resolve reads the aliases again. */
    public function flush(): void
    {
        $this->map = null;
    }

    /** The normalised form of a status or an alias (see the class note), or null when nothing is left of it. */
    public static function normalise(?string $status): ?string
    {
        if ($status === null) {
            return null;
        }

        $value = (string) preg_replace('/\s+/u', ' ', trim($status));
        // A trailing slash is the tracker's punctuation, not part of the status; a slash inside is (SANFO/CAL).
        $value = trim(rtrim($value, " \t\n\r\0\x0B/"));

        return $value === '' ? null : mb_strtoupper($value);
    }

    /**
     * @return array{exact: array<string, array{stage_id: int}>, wildcard: list<array{regex: string, ids: array{stage_id: int}}>, blank: array{stage_id: int}|null}
     */
    private function map(): array
    {
        if ($this->map !== null) {
            return $this->map;
        }

        $exact = [];
        $blank = null;
        /** @var list<array{regex: string, ids: array{stage_id: int}, weight: int, order: int}> $wildcard */
        $wildcard = [];
        // The stages arrive in stage order, so the loop counter *is* that order.
        $order = 0;

        foreach ($this->aliasRows() as $row) {
            $order++;
            $ids = ['stage_id' => $row['stage_id']];

            foreach ($row['aliases'] as $alias) {
                if (strcasecmp(trim($alias), Stage::BLANK_ALIAS) === 0) {
                    // First claim wins here too: two stages claiming "no status" is a master-data mistake.
                    $blank ??= $ids;

                    continue;
                }
                $normalised = self::normalise($alias);
                if ($normalised === null) {
                    continue;
                }
                if (! str_contains($normalised, '*')) {
                    // First claim wins: two stages claiming one status is a master-data mistake, and
                    // silently preferring the later one would make resolution depend on row order.
                    $exact[$normalised] ??= $ids;

                    continue;
                }
                $wildcard[] = [
                    'regex' => '/^'.str_replace('\*', '.*', preg_quote($normalised, '/')).'$/u',
                    'ids' => $ids,
                    'weight' => mb_strlen(str_replace('*', '', $normalised)),
                    'order' => $order,
                ];
            }
        }

        usort($wildcard, fn (array $a, array $b): int => [-$a['weight'], $a['order']] <=> [-$b['weight'], $b['order']]);

        return $this->map = [
            'exact' => $exact,
            'wildcard' => array_map(fn (array $one): array => ['regex' => $one['regex'], 'ids' => $one['ids']], $wildcard),
            'blank' => $blank,
        ];
    }

    /**
     * The alias rows: one per active stage, in stage order. Plain arrays, not models, so the matching rules
     * above depend on data and nothing else.
     *
     * @return iterable<array{stage_id: int, aliases: list<string>}>
     */
    private function aliasRows(): iterable
    {
        if ($this->loader !== null) {
            return ($this->loader)();
        }

        return Stage::query()
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('id')
            ->get()
            ->map(fn (Stage $stage): array => [
                'stage_id' => (int) $stage->getKey(),
                'aliases' => $stage->aliasList(),
            ])
            ->all();
    }
}
