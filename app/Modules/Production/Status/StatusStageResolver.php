<?php

namespace App\Modules\Production\Status;

use App\Modules\Foundation\Models\StageProcess;
use Closure;

/**
 * Raw status (`lots.current_label`) -> stage / stage process, from the `stage_processes.status_aliases`
 * column. Nothing here hard-codes a status string: the mapping is master data, so a status the mill starts
 * writing tomorrow is mapped on the Stage Processes master, not in a deploy.
 *
 * **Normalisation** (spec §8), applied to the status *and* to every alias before they are compared — the
 * stored status itself is never changed:
 *
 *   1. leading and trailing whitespace removed;
 *   2. every run of whitespace inside collapsed to one space;
 *   3. trailing `/` and spaces removed (the tracker writes `DYEING/`, `DYEING /` and `DYEING` for one thing);
 *   4. case folded to upper case.
 *
 * So `DYEING`, `dyeing`, `DYEING/` and `DYEING  / ` all resolve to the same process, while the slash *inside*
 * `SANFO/CAL` is kept — only a trailing one is punctuation.
 *
 * **Matching**, in this order:
 *
 *   1. an exact alias;
 *   2. a wildcard alias (an alias containing `*`, matched as `.*`), most specific first — "most specific" is
 *      the alias with the most literal (non-wildcard) characters, ties going to whichever process comes
 *      first in stage then process order. This is how the dispatch family (`DISP 11-09-26/` -> `DISP *`) and
 *      "anything containing CANCEL" (`*CANCEL*`) are expressed without one alias per date;
 *   3. nothing: both ids come back null. An unknown status is reported by the Unmapped Statuses screen and
 *      never guessed (a wrong stage is worse in a report than an empty one).
 *
 * The map is read once and memoised, so a backfill over every lot is one query, not one per lot. It is
 * registered per request (`scoped`) and flushed whenever a stage or process is saved or deleted.
 */
final class StatusStageResolver
{
    /** @var array{stage_id: null, stage_process_id: null} */
    private const UNRESOLVED = ['stage_id' => null, 'stage_process_id' => null];

    /**
     * exact: normalised alias => ids; wildcard: the same, as anchored regexes, most specific first.
     *
     * @var array{exact: array<string, array{stage_id: int, stage_process_id: int}>, wildcard: list<array{regex: string, ids: array{stage_id: int, stage_process_id: int}}>}|null
     */
    private ?array $map = null;

    /**
     * @param  (Closure(): iterable<array{stage_id: int, stage_process_id: int, aliases: list<string>}>)|null  $loader
     *                                                                                                                  the alias rows, in stage then process order; the default reads the active processes from
     *                                                                                                                  the database. A test passes its own literal rows, so the normalisation and matching rules
     *                                                                                                                  are exercised with no database and no Eloquent at all.
     */
    public function __construct(private readonly ?Closure $loader = null) {}

    /**
     * The stage and process a raw status means, or two nulls when nothing claims it.
     *
     * @return array{stage_id: int|null, stage_process_id: int|null}
     */
    public function resolve(?string $status): array
    {
        $needle = self::normalise($status);
        if ($needle === null) {
            return self::UNRESOLVED;
        }

        $map = $this->map();
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

    /** Whether some process claims this status. A blank or null status is not "unmapped" — it says nothing. */
    public function isMapped(?string $status): bool
    {
        return $this->resolve($status)['stage_process_id'] !== null;
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
     * @return array{exact: array<string, array{stage_id: int, stage_process_id: int}>, wildcard: list<array{regex: string, ids: array{stage_id: int, stage_process_id: int}}>}
     */
    private function map(): array
    {
        if ($this->map !== null) {
            return $this->map;
        }

        $exact = [];
        /** @var list<array{regex: string, ids: array{stage_id: int, stage_process_id: int}, weight: int, order: int}> $wildcard */
        $wildcard = [];
        // The processes arrive in stage then process order, so the loop counter *is* that order.
        $order = 0;

        foreach ($this->aliasRows() as $row) {
            $order++;
            $ids = ['stage_id' => $row['stage_id'], 'stage_process_id' => $row['stage_process_id']];

            foreach ($row['aliases'] as $alias) {
                $normalised = self::normalise($alias);
                if ($normalised === null) {
                    continue;
                }
                if (! str_contains($normalised, '*')) {
                    // First claim wins: two processes claiming one status is a master-data mistake, and
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
        ];
    }

    /**
     * The alias rows: one per active process of an active stage, in stage then process order. Plain arrays,
     * not models, so the matching rules above depend on data and nothing else.
     *
     * @return iterable<array{stage_id: int, stage_process_id: int, aliases: list<string>}>
     */
    private function aliasRows(): iterable
    {
        if ($this->loader !== null) {
            return ($this->loader)();
        }

        return StageProcess::query()
            ->join('stages', 'stages.id', '=', 'stage_processes.stage_id')
            ->where('stage_processes.is_active', true)
            ->where('stages.is_active', true)
            ->orderBy('stages.sort_order')->orderBy('stages.id')
            ->orderBy('stage_processes.sort_order')->orderBy('stage_processes.id')
            ->select('stage_processes.*')
            ->get()
            ->map(fn (StageProcess $process): array => [
                'stage_id' => (int) $process->stage_id,
                'stage_process_id' => (int) $process->getKey(),
                'aliases' => $process->aliasList(),
            ])
            ->all();
    }
}
