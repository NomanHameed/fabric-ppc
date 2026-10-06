<?php

namespace App\Modules\Activity\Console;

use App\Modules\Activity\Models\UserAction;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Retention for `user_actions`.
 *
 * The model refuses `delete()` on purpose — the log is insert-only, so no request, no observer and no stray
 * `model:prune` can rewrite history. That guard also means the table has no way to stop growing, and it gains a
 * row per signup, approval, issue, sheet line and capacity allocation: on a mill floor that is thousands a week.
 *
 * Retention is therefore an explicit, deliberate operator action: this command, run on a schedule, deleting
 * through the query builder (which the model guard does not sit on) by the indexed `occurred_at` column. It is
 * the only sanctioned way rows leave the table; `UserAction::delete()` still throws.
 *
 * Deleting in chunks keeps one run from taking a table-wide lock for minutes on a large table.
 */
class PruneUserActions extends Command
{
    /** Default retention: two years of floor history, which is what the mill keeps its paper sheets for. */
    public const DEFAULT_DAYS = 730;

    /** Rows deleted per statement, so a long backlog never holds one lock for the whole run. */
    public const CHUNK = 1000;

    protected $signature = 'activity:prune {--days= : Keep this many days of history (default '.self::DEFAULT_DAYS.')} {--pretend : Report what would be deleted and delete nothing}';

    protected $description = 'Delete user_actions rows older than the retention window.';

    public function handle(): int
    {
        $days = $this->option('days');
        $days = $days === null || $days === '' ? self::DEFAULT_DAYS : (int) $days;

        if ($days < 1) {
            $this->components->error('--days must be at least 1; refusing to delete the whole action log.');

            return self::FAILURE;
        }

        $cutoff = CarbonImmutable::now()->subDays($days)->startOfDay();
        $table = (new UserAction)->getTable();

        if ($this->option('pretend')) {
            $count = DB::table($table)->where('occurred_at', '<', $cutoff)->count();
            $this->components->info("{$count} action(s) older than {$cutoff->toDateTimeString()} would be deleted.");

            return self::SUCCESS;
        }

        $deleted = 0;
        do {
            // Query builder, not the model: UserAction::deleting() throws by design (see the class docblock).
            $batch = DB::table($table)->where('occurred_at', '<', $cutoff)->limit(self::CHUNK)->delete();
            $deleted += $batch;
        } while ($batch === self::CHUNK);

        $this->components->info("Deleted {$deleted} action(s) older than {$cutoff->toDateTimeString()}.");

        return self::SUCCESS;
    }
}
