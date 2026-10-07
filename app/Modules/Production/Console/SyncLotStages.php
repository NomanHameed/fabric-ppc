<?php

namespace App\Modules\Production\Console;

use App\Models\User;
use App\Modules\OracleSync\Services\ImportCommitService;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Status\StatusStageResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Exceptions\RoleDoesNotExist;

/**
 * Give every existing lot its stage and stage process, resolved from the status it already carries
 * (spec §9). Run once after the stages are seeded, and again after new aliases are mapped.
 *
 * Safe by construction: it reads `lots.current_label` and writes **only** `stage_id` and `stage_process_id`.
 * No quantity, no label, no movement and no balance is touched, so it cannot change what the mill is owed
 * or what the report counts — only where a lot is grouped. Idempotent: a second run finds nothing to do.
 * `--pretend` writes nothing at all and prints the same summary.
 *
 * A status nothing claims leaves both columns null and is listed at the end with its lot count, which is the
 * same set of statuses the Unmapped Statuses screen shows. Nothing is guessed.
 *
 * A lot whose stage PPC has pinned by hand (`lots.stage_overridden_at`) is skipped untouched and counted
 * separately: an override is a decision, and a backfill must not quietly undo decisions.
 */
class SyncLotStages extends Command
{
    /** Lots per chunk. Keyset pagination (chunkById), so a concurrent insert cannot shift the window. */
    private const CHUNK = 500;

    /** Unmapped statuses listed at the end; more than this and the screen is the better place to look. */
    private const MAX_REPORTED = 25;

    protected $signature = 'production:sync-stages
        {--pretend : Report what would change without writing anything}
        {--user= : User id recorded as the actor}';

    protected $description = 'Resolve every lot\'s reporting stage and process from its status label (idempotent)';

    public function handle(StatusStageResolver $resolver): int
    {
        $user = $this->actingUser();
        if ($user === null) {
            $this->error(__('production.errors.no_cli_user'));

            return self::FAILURE;
        }

        $pretend = (bool) $this->option('pretend');
        $scanned = 0;
        $changed = 0;
        $resolved = 0;
        $overridden = 0;
        /** @var array<string, int> $unmapped status => lot count */
        $unmapped = [];

        // Audited like BackfillLedger, even though the two columns are written with the query builder (as
        // LedgerService writes them) and so produce no audit rows: anything this command might come to write
        // is then already attributed, rather than landing as a console write with no actor.
        ImportCommitService::asAuditUser($user, function () use ($resolver, $pretend, &$scanned, &$changed, &$resolved, &$overridden, &$unmapped): void {
            Lot::query()->orderBy('id')
                ->chunkById(self::CHUNK, function (Collection $lots) use ($resolver, $pretend, &$scanned, &$changed, &$resolved, &$overridden, &$unmapped): void {
                    foreach ($lots as $lot) {
                        $scanned++;
                        if ($lot->isStageOverridden()) {
                            // Pinned by hand: not resolved, not unmapped, not written.
                            $overridden++;

                            continue;
                        }
                        $ids = $resolver->resolve($lot->current_label);

                        if ($ids['stage_process_id'] === null) {
                            $label = (string) ($lot->current_label ?? '');
                            if (StatusStageResolver::normalise($label) !== null) {
                                $unmapped[$label] = ($unmapped[$label] ?? 0) + 1;
                            }
                        } else {
                            $resolved++;
                        }

                        if ($ids['stage_id'] === $lot->stage_id && $ids['stage_process_id'] === $lot->stage_process_id) {
                            continue;
                        }
                        $changed++;
                        if (! $pretend) {
                            // DB::table, not Lot::query()->update(): these are derived columns, and a
                            // backfill must not stamp `updated_at` on every lot in the mill.
                            DB::table('lots')->where('id', $lot->getKey())->update($ids);
                        }
                    }
                });
        });

        $this->report($scanned, $changed, $resolved, $overridden, $unmapped, $pretend);

        return self::SUCCESS;
    }

    /** @param array<string, int> $unmapped */
    private function report(int $scanned, int $changed, int $resolved, int $overridden, array $unmapped, bool $pretend): void
    {
        $this->info(($pretend ? 'Would update ' : 'Updated ').$changed." of {$scanned} lot(s): "
            ."{$resolved} resolved, ".($scanned - $resolved - $overridden)
            ." unresolved, {$overridden} skipped (stage overridden by hand).");

        if ($unmapped === []) {
            return;
        }

        arsort($unmapped);
        $this->warn(count($unmapped).' status(es) no stage process claims:');
        $this->table(
            ['Status', 'Lots'],
            array_map(
                fn (string $status, int $count): array => [$status, $count],
                array_slice(array_keys($unmapped), 0, self::MAX_REPORTED),
                array_slice(array_values($unmapped), 0, self::MAX_REPORTED),
            ),
        );
        if (count($unmapped) > self::MAX_REPORTED) {
            $this->line('… and '.(count($unmapped) - self::MAX_REPORTED).' more; see the Unmapped Statuses screen.');
        }
    }

    /** The user the run is attributed to: `--user`, else any admin (BackfillLedger's rule). */
    private function actingUser(): ?User
    {
        $userId = $this->option('user');
        if ($userId === null) {
            try {
                $userId = User::query()->role('admin')->value('id');
            } catch (RoleDoesNotExist) {
                $userId = null;
            }
        }

        return $userId === null ? null : User::query()->whereKey($userId)->first();
    }
}
