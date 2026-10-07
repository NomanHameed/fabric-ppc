<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The reporting taxonomy: a short list of stages, each holding the processes a lot can sit at.
 *
 * Deliberately not `departments` (the mill's 12 physical cost centres) nor `operations` (its 112 named
 * operations): a stage is the bucket the Month Wise Fabric Report groups by, and it includes states that are
 * not places on the floor at all — approval pending, hold, returned to the party, outsourced, LCR review,
 * debit, dispatched, cancelled. Those have no department and no operation, which is exactly why neither of
 * the two existing tables can carry them.
 *
 * `status_aliases` holds the raw status strings (`lots.current_label`) that resolve to the process, which is
 * what makes the mapping database-driven: a status nobody mapped yet is added here, on the Stage Processes
 * master, with no code change. `*` in an alias is a wildcard, so the dispatch family (`DISP 11-09-26/`) and
 * anything containing CANCEL are patterns rather than one row per date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stages', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();
            $t->string('name', 60);
            $t->string('description', 255)->nullable();
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->index(['is_active', 'sort_order']);
        });

        Schema::create('stage_processes', function (Blueprint $t) {
            $t->id();
            // restrictOnDelete: a stage that still has processes is never deleted silently; the master screen
            // turns the FK violation into "in use" and the operator deactivates it instead.
            $t->foreignId('stage_id')->constrained()->restrictOnDelete();
            $t->string('code', 40);
            $t->string('name', 80);
            $t->string('description', 255)->nullable();
            $t->unsignedSmallInteger('sort_order')->default(0);
            // Which of the report's quantities this process carries (StageProcess::QUANTITY_TYPES), or null
            // for a state that holds no metres of its own (approval pending, hold, debit, cancelled).
            $t->string('quantity_type', 20)->nullable();
            $t->boolean('is_wip')->default(false);
            $t->boolean('is_finished')->default(false);
            $t->boolean('is_dispatch')->default(false);
            $t->json('status_aliases')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['stage_id', 'code']);
            $t->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_processes');
        Schema::dropIfExists('stages');
    }
};
