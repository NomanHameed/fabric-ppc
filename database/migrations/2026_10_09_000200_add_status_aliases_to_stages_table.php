<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Move the status mapping up one level: the raw statuses (`lots.status_oracle`) that mean a reporting bucket
 * now live on the **stage**, in `stages.status_aliases`, with exactly the semantics they had on
 * `stage_processes.status_aliases` — a JSON list, `*` as a wildcard, compared after normalisation.
 *
 * Resolution is stage-only from here (`lots.stage_process_id` is gone), so an alias on a process could no
 * longer say anything. The existing sets are collapsed onto their stage here rather than in the seeder
 * alone, so a database that is migrated but not re-seeded still resolves every status it resolved before.
 *
 * **Collapsing is a union, not a merge of equals.** Two processes of one stage may claim the same status
 * (the taxonomy allowed it; the resolver's "first claim wins" hid it), so duplicates are dropped by their
 * normalised form and the first spelling encountered is the one kept. The order within a stage is stage
 * process order, so the wildcard precedence the resolver derives is unchanged.
 *
 * `stage_processes.status_aliases` is deliberately **left in place and left populated**: the table and its
 * model survive this change unreferenced by `lots`, and keeping the column means `down()` needs no data of
 * its own to restore.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stages', fn (Blueprint $t) => $t->json('status_aliases')->nullable()->after('description'));

        foreach (DB::table('stages')->orderBy('id')->pluck('id') as $stageId) {
            $aliases = $this->collapse((int) $stageId);
            if ($aliases !== []) {
                DB::table('stages')->where('id', $stageId)->update(['status_aliases' => json_encode($aliases)]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('stages', fn (Blueprint $t) => $t->dropColumn('status_aliases'));
    }

    /**
     * Every alias of every process of one stage, in process order, deduplicated by normalised form.
     *
     * The normalisation is restated here rather than taken from `StatusStageResolver`: a migration must keep
     * doing what it did on the day it ran, whatever the application class later becomes.
     *
     * @return list<string>
     */
    private function collapse(int $stageId): array
    {
        $rows = DB::table('stage_processes')->where('stage_id', $stageId)
            ->orderBy('sort_order')->orderBy('id')->pluck('status_aliases');

        $seen = [];
        $out = [];
        foreach ($rows as $json) {
            $decoded = is_string($json) ? json_decode($json, true) : null;
            if (! is_array($decoded)) {
                continue;
            }
            foreach ($decoded as $alias) {
                if (! is_scalar($alias)) {
                    continue;
                }
                $alias = trim((string) $alias);
                $key = mb_strtoupper(trim(rtrim((string) preg_replace('/\s+/u', ' ', $alias), " \t\n\r\0\x0B/")));
                if ($alias === '' || $key === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $out[] = $alias;
            }
        }

        return $out;
    }
};
