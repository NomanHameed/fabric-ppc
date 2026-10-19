<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rebuild `stage_processes` around the mill's own Oracle production fields.
 *
 * **What this table now holds.** One Oracle production line — party, PO, reference, design, colour, colour
 * status, PO type, PO date, lot number, quality, greige width, status and the six quantities — linked to the
 * reporting stage it belongs to (`stage_id`). It is no longer the process-name taxonomy: `code`, `name`,
 * `description`, `sort_order`, `quantity_type`, `is_wip`, `is_finished`, `is_dispatch`, `status_aliases` and
 * `is_active` are gone, with the `unique(stage_id, code)` and `index(is_active, sort_order)` that read them.
 *
 * **This is a deliberate, user-directed restructure, not a mistake to fix back.** The user asked for exactly
 * this column list on exactly this table, was shown that the same twenty columns already exist on `lots` and
 * that the eighteen seeded process rows lose their meaning, and repeated the instruction verbatim. Please do
 * not "restore" the taxonomy: `down()` below does that, and it is there for a rollback, not for a tidy-up.
 *
 * **The columns mirror `lots`.** Every type, length and precision is copied from the matching `lots` column
 * (`2026_09_19_000500`, `2026_09_19_000800`, `2026_10_09_000100`) so the two tables agree and a future import
 * can move a row between them without a cast. Two deliberate differences:
 *
 *  - The six quantities are `decimal(12,2)`, not `int`. CLAUDE.md fixes quantities at `decimal(12,2)` and
 *    `lots` already is; an integer would silently truncate metres.
 *  - Every column is nullable, including the ones that are NOT NULL on `lots` (`party_oracle_name`, `po_no`,
 *    `po_type_oracle`, `date_oracle`, `lot_no_oracle`, `quality` and the six quantities). Nothing imports
 *    into this table yet, so a NOT NULL column would make it unseedable and unusable from the master screen.
 *
 * `date_oracle` is `dateTime`, never `timestamp`: on MariaDB the first NOT NULL TIMESTAMP in a table
 * silently gains `ON UPDATE CURRENT_TIMESTAMP` (CLAUDE.md), which would rewrite the PO date on every save.
 *
 * **The eighteen seeded rows are deleted.** They were the process taxonomy (Bleaching, Dyeing, Dispatch, …)
 * and every column that carried their meaning is dropped here, so keeping them would leave eighteen rows
 * holding nothing but a stage id. `StageSeeder` no longer seeds processes at all, so a re-seed does not
 * bring them back. Nothing referenced them: `lots.stage_process_id` went in `2026_10_09_000100` and status
 * resolution has been stage-only since, so no lot and no report loses anything.
 *
 * **Identity.** `unique(stage_id, code)` is replaced by `unique(stage_id, po_no, lot_no_oracle)`: a lot
 * number alone is not unique even on `lots` (the mill restarts lot numbers per PO, which is why `lots` only
 * indexes it), so the PO has to be part of the key. All three are nullable and both MySQL and SQLite treat
 * NULLs as distinct in a unique index, so any number of blank rows can still be entered.
 *
 * `stage_id` keeps its `restrictOnDelete`: a stage with rows here is still never deleted silently, and the
 * master screen still turns the foreign key violation into `forms.in_use`. It gains a plain index of its own
 * first, because the unique being dropped was the only index the foreign key had (MySQL errno 150).
 */
return new class extends Migration
{
    public function up(): void
    {
        // The taxonomy rows, before the columns that gave them meaning go.
        DB::table('stage_processes')->delete();

        Schema::table('stage_processes', function (Blueprint $t) {
            $t->unsignedInteger('party_oracle_id')->nullable()->after('stage_id');
            $t->string('party_oracle_name', 150)->nullable()->after('party_oracle_id');
            $t->unsignedInteger('po_no')->nullable()->after('party_oracle_name');
            $t->string('ref', 60)->nullable()->after('po_no');
            $t->string('design_oracle_id', 20)->nullable()->after('ref');
            $t->string('color_oracle_id', 20)->nullable()->after('design_oracle_id');
            $t->string('color_oracle_name', 80)->nullable()->after('color_oracle_id');
            $t->string('clr_status_oracle', 40)->nullable()->after('color_oracle_name');
            $t->string('po_type_oracle', 40)->nullable()->after('clr_status_oracle');
            $t->dateTime('date_oracle')->nullable()->after('po_type_oracle');
            $t->string('lot_no_oracle', 10)->nullable()->after('date_oracle');
            $t->string('quality', 255)->nullable()->after('lot_no_oracle');
            $t->decimal('gw_oracle', 6, 2)->nullable()->after('quality');
            $t->string('status_oracle', 40)->nullable()->after('gw_oracle');
            $t->decimal('greige_received_oracle', 12, 2)->nullable()->after('status_oracle');
            $t->decimal('greige_in_stock_oracle', 12, 2)->nullable()->after('greige_received_oracle');
            $t->decimal('qty_issued', 12, 2)->nullable()->after('greige_in_stock_oracle');
            $t->decimal('wip', 12, 2)->nullable()->after('qty_issued');
            $t->decimal('finished', 12, 2)->nullable()->after('wip');
            $t->decimal('dispatch', 12, 2)->nullable()->after('finished');
        });

        // `unique(stage_id, code)` is the index MySQL gave the foreign key when the table was created, so
        // `stage_id` needs one of its own before that unique can go.
        Schema::table('stage_processes', fn (Blueprint $t) => $t->index('stage_id', 'stage_processes_stage_id_index'));

        Schema::table('stage_processes', function (Blueprint $t) {
            $t->dropUnique(['stage_id', 'code']);
            $t->dropIndex(['is_active', 'sort_order']);
        });

        Schema::table('stage_processes', fn (Blueprint $t) => $t->dropColumn([
            'code', 'name', 'description', 'sort_order', 'quantity_type',
            'is_wip', 'is_finished', 'is_dispatch', 'status_aliases', 'is_active',
        ]));

        Schema::table('stage_processes', fn (Blueprint $t) => $t->unique(
            ['stage_id', 'po_no', 'lot_no_oracle'],
            'stage_processes_stage_po_lot_unique',
        ));
    }

    /**
     * Restore the taxonomy columns and their two indexes.
     *
     * The Oracle columns are dropped, so a row that was an Oracle production line keeps only its stage. The
     * rows are kept rather than deleted — a rollback should not destroy what the operator entered — and each
     * is given a synthetic `code` / `name` from its id, because `unique(stage_id, code)` has to hold and an
     * Oracle line has no code of its own.
     *
     * `code` and `name` come back with `default('')` rather than with no default at all: SQLite refuses to
     * add a NOT NULL column without one, and on MySQL an implicit `''` is what `ALTER TABLE … ADD COLUMN`
     * would have used anyway. Every other restored definition is the original one.
     */
    public function down(): void
    {
        Schema::table('stage_processes', fn (Blueprint $t) => $t->dropUnique('stage_processes_stage_po_lot_unique'));

        Schema::table('stage_processes', function (Blueprint $t) {
            $t->string('code', 40)->default('')->after('stage_id');
            $t->string('name', 80)->default('')->after('code');
            $t->string('description', 255)->nullable()->after('name');
            $t->unsignedSmallInteger('sort_order')->default(0)->after('description');
            $t->string('quantity_type', 20)->nullable()->after('sort_order');
            $t->boolean('is_wip')->default(false)->after('quantity_type');
            $t->boolean('is_finished')->default(false)->after('is_wip');
            $t->boolean('is_dispatch')->default(false)->after('is_finished');
            $t->json('status_aliases')->nullable()->after('is_dispatch');
            $t->boolean('is_active')->default(true)->after('status_aliases');
        });

        foreach (DB::table('stage_processes')->orderBy('id')->pluck('id') as $id) {
            DB::table('stage_processes')->where('id', $id)
                ->update(['code' => 'ROW_'.$id, 'name' => 'Restored row '.$id]);
        }

        Schema::table('stage_processes', function (Blueprint $t) {
            $t->unique(['stage_id', 'code']);
            $t->index(['is_active', 'sort_order']);
        });

        // Only now that the unique covers `stage_id` again may its own index go.
        Schema::table('stage_processes', fn (Blueprint $t) => $t->dropIndex('stage_processes_stage_id_index'));

        Schema::table('stage_processes', fn (Blueprint $t) => $t->dropColumn([
            'party_oracle_id', 'party_oracle_name', 'po_no', 'ref', 'design_oracle_id', 'color_oracle_id',
            'color_oracle_name', 'clr_status_oracle', 'po_type_oracle', 'date_oracle', 'lot_no_oracle',
            'quality', 'gw_oracle', 'status_oracle', 'greige_received_oracle', 'greige_in_stock_oracle',
            'qty_issued', 'wip', 'finished', 'dispatch',
        ]));
    }
};
