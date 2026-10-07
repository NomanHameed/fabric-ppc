<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a lot sits in the reporting taxonomy, resolved from `lots.current_label`.
 *
 * Both columns are nullable and stay null when the label resolves to nothing: an unmapped status is reported
 * (Unmapped Statuses screen) and never guessed. Normally they are derived columns like the quantities beside
 * them — LedgerService writes them in the same update that writes `current_label`, so they cannot drift.
 *
 * `stage_overridden_at` / `stage_overridden_by` are the one exception: while they are set, PPC has pinned
 * this lot's stage by hand and the ledger leaves the two columns alone (it keeps writing the label and every
 * quantity). The moment the override is cleared the stage is re-derived. The two columns are recorded, not
 * implied, so a stage a human pinned is never shown as one the system worked out — `dateTime`, not
 * `timestamp` (CLAUDE.md: on MariaDB the first NOT NULL TIMESTAMP silently gains ON UPDATE
 * CURRENT_TIMESTAMP).
 *
 * nullOnDelete rather than restrict: deleting a stage is already refused while a process points at it, and a
 * lot losing its stage is a reporting gap the Unmapped Statuses screen surfaces, not a reason to block the
 * delete of master data that was created by mistake.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lots', function (Blueprint $t) {
            $t->foreignId('stage_id')->nullable()->after('current_label')->constrained()->nullOnDelete();
            $t->foreignId('stage_process_id')->nullable()->after('stage_id')->constrained()->nullOnDelete();
            $t->dateTime('stage_overridden_at')->nullable()->after('stage_process_id');
            $t->foreignId('stage_overridden_by')->nullable()->after('stage_overridden_at')
                ->constrained('users')->nullOnDelete();
            // The report groups stage -> stage process; this is the index that grouping reads.
            $t->index(['stage_id', 'stage_process_id']);
        });
    }

    public function down(): void
    {
        Schema::table('lots', function (Blueprint $t) {
            $t->dropIndex(['stage_id', 'stage_process_id']);
            $t->dropConstrainedForeignId('stage_overridden_by');
            $t->dropColumn('stage_overridden_at');
            $t->dropConstrainedForeignId('stage_process_id');
            $t->dropConstrainedForeignId('stage_id');
        });
    }
};
