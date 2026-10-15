<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rename the `lots` columns to the mill's own Oracle field names, and denormalise onto the lot the five
 * fields the Month Wise Fabric Report prints from the PO, the party and the quality.
 *
 * **This is a rename, not a rebuild.** Every existing column is moved with `renameColumn`, which on MySQL
 * 8.0.3+ compiles to `ALTER TABLE … RENAME COLUMN` and on MariaDB below 10.5.2 (this project's dev database
 * is 10.4) to `ALTER TABLE … CHANGE`, restating the definition Laravel reads back from the connection. Both
 * keep the data, the indexes and the foreign keys; `doctrine/dbal` has not been needed since Laravel 11 and
 * is not installed. No column is dropped and re-created, so not one metre of production is lost.
 *
 * **The six new columns are additive copies, not replacements.** `lots.contract_id` and `lots.quality_id`
 * stay exactly as they were, with their foreign keys, so the Inquiry and PO screens, the Oracle import and
 * every join that reads the PO or the quality keep working. The copies exist so the report can print its
 * twenty columns without five joins, and are backfilled here from the joined rows.
 *
 * `date_oracle` is `dateTime`, never `timestamp`: on MariaDB the first NOT NULL TIMESTAMP in a table
 * silently gains `ON UPDATE CURRENT_TIMESTAMP` (CLAUDE.md), which would rewrite the PO date on every save.
 *
 * `party_oracle_id` and `ref` stay nullable, because their sources are (`parties.oracle_code` is null for
 * every party seeded from the mill's list, `contracts.ref_no` is optional). The other four are made NOT NULL
 * only when the backfill actually populated every row — a column is never declared non-null on the strength
 * of what the schema says its source *should* hold.
 *
 * `lots.stage_process_id` goes. Stage resolution is stage-only from here (`StatusStageResolver` resolves a
 * status to a stage, `stages.status_aliases` holds the aliases); the `stage_processes` table and model are
 * deliberately left in place, unreferenced by `lots`, so the decision stays reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. The denormalised copies, nullable so the backfill has somewhere to land.
        Schema::table('lots', function (Blueprint $t) {
            $t->unsignedInteger('party_oracle_id')->nullable()->after('quality_id');
            $t->string('party_oracle_name', 150)->nullable()->after('party_oracle_id');
            $t->unsignedInteger('po_no')->nullable()->after('party_oracle_name');
            $t->string('ref', 60)->nullable()->after('po_no');
            $t->dateTime('date_oracle')->nullable()->after('ref');
            $t->string('quality', 255)->nullable()->after('date_oracle');
        });

        // 2. The renames, in the order the report prints the columns. Data, indexes and foreign keys
        //    travel with the column.
        //
        //    Written out one call per column rather than looped over a map: larastan builds the model's
        //    property list by parsing these migrations, and it only follows a `renameColumn` whose
        //    arguments are literal strings — a loop would leave every renamed property "undefined" to
        //    static analysis.
        Schema::table('lots', function (Blueprint $t) {
            $t->renameColumn('design_code', 'design_oracle_id');
            $t->renameColumn('colour_code', 'color_oracle_id');
            $t->renameColumn('colour', 'color_oracle_name');
            $t->renameColumn('colour_status', 'clr_status_oracle');
            $t->renameColumn('po_type', 'po_type_oracle');
            $t->renameColumn('lot_no', 'lot_no_oracle');
            $t->renameColumn('g_width', 'gw_oracle');
            $t->renameColumn('current_label', 'status_oracle');
            $t->renameColumn('greige_qty', 'greige_received_oracle');
            $t->renameColumn('stock_qty', 'greige_in_stock_oracle');
            $t->renameColumn('issued_qty', 'qty_issued');
            $t->renameColumn('wip_qty', 'wip');
            $t->renameColumn('finished_qty', 'finished');
            $t->renameColumn('dispatched_qty', 'dispatch');
        });

        // 3. The backfill, from the joined rows. Correlated sub-selects rather than `UPDATE … JOIN`, which
        //    MySQL and SQLite spell differently; `lots` is never selected from while it is being updated.
        $this->backfill();

        // 4. NOT NULL only where every row really is populated.
        foreach (['party_oracle_name' => 150, 'quality' => 255] as $column => $length) {
            if (DB::table('lots')->whereNull($column)->doesntExist()) {
                Schema::table('lots', fn (Blueprint $t) => $t->string($column, $length)->nullable(false)->change());
            }
        }
        if (DB::table('lots')->whereNull('po_no')->doesntExist()) {
            Schema::table('lots', fn (Blueprint $t) => $t->unsignedInteger('po_no')->nullable(false)->change());
        }
        if (DB::table('lots')->whereNull('date_oracle')->doesntExist()) {
            Schema::table('lots', fn (Blueprint $t) => $t->dateTime('date_oracle')->nullable(false)->change());
        }

        // 5. Indexes. The renamed columns keep their indexes automatically, but not the index *names*, so the
        //    three that name a renamed column are dropped and re-created under the new name — portable, and
        //    cheaper than `RENAME INDEX`, which MariaDB 10.4 does not have. `stage_id` gets an index of its
        //    own before the composite one goes, so its foreign key is never left without one.
        Schema::table('lots', function (Blueprint $t) {
            $t->index('stage_id', 'lots_stage_id_index');
            $t->index('po_no', 'lots_po_no_index');
            $t->dropIndex('lots_lot_no_index');
            $t->index('lot_no_oracle', 'lots_lot_no_oracle_index');
            $t->dropIndex('lots_state_lot_no_index');
            $t->index(['state', 'lot_no_oracle'], 'lots_state_lot_no_oracle_index');
            $t->dropIndex('lots_state_current_label_index');
            $t->index(['state', 'status_oracle'], 'lots_state_status_oracle_index');
        });

        // 6. Stage resolution is stage-only now.
        Schema::table('lots', function (Blueprint $t) {
            $t->dropIndex('lots_stage_id_stage_process_id_index');
            $t->dropConstrainedForeignId('stage_process_id');
        });
    }

    public function down(): void
    {
        Schema::table('lots', function (Blueprint $t) {
            $t->foreignId('stage_process_id')->nullable()->after('stage_id')->constrained()->nullOnDelete();
            $t->index(['stage_id', 'stage_process_id'], 'lots_stage_id_stage_process_id_index');
        });

        Schema::table('lots', function (Blueprint $t) {
            $t->dropIndex('lots_stage_id_index');
            $t->dropIndex('lots_po_no_index');
            $t->dropIndex('lots_lot_no_oracle_index');
            $t->dropIndex('lots_state_lot_no_oracle_index');
            $t->dropIndex('lots_state_status_oracle_index');
        });

        Schema::table('lots', function (Blueprint $t) {
            $t->renameColumn('dispatch', 'dispatched_qty');
            $t->renameColumn('finished', 'finished_qty');
            $t->renameColumn('wip', 'wip_qty');
            $t->renameColumn('qty_issued', 'issued_qty');
            $t->renameColumn('greige_in_stock_oracle', 'stock_qty');
            $t->renameColumn('greige_received_oracle', 'greige_qty');
            $t->renameColumn('status_oracle', 'current_label');
            $t->renameColumn('gw_oracle', 'g_width');
            $t->renameColumn('lot_no_oracle', 'lot_no');
            $t->renameColumn('po_type_oracle', 'po_type');
            $t->renameColumn('clr_status_oracle', 'colour_status');
            $t->renameColumn('color_oracle_name', 'colour');
            $t->renameColumn('color_oracle_id', 'colour_code');
            $t->renameColumn('design_oracle_id', 'design_code');
        });

        Schema::table('lots', function (Blueprint $t) {
            $t->index('lot_no', 'lots_lot_no_index');
            $t->index(['state', 'lot_no'], 'lots_state_lot_no_index');
            $t->index(['state', 'current_label'], 'lots_state_current_label_index');
            $t->dropColumn(['party_oracle_id', 'party_oracle_name', 'po_no', 'ref', 'date_oracle', 'quality']);
        });
    }

    /** The denormalised copies, read out of the PO, its party and the lot's quality. */
    private function backfill(): void
    {
        DB::table('lots')->update([
            'party_oracle_id' => DB::raw('(select p.oracle_code from parties p inner join contracts c on c.party_id = p.id where c.id = lots.contract_id)'),
            'party_oracle_name' => DB::raw('(select p.name from parties p inner join contracts c on c.party_id = p.id where c.id = lots.contract_id)'),
            'po_no' => DB::raw('(select c.po_no from contracts c where c.id = lots.contract_id)'),
            'ref' => DB::raw('(select c.ref_no from contracts c where c.id = lots.contract_id)'),
            'date_oracle' => DB::raw('(select c.po_date from contracts c where c.id = lots.contract_id)'),
            'quality' => DB::raw('(select q.description from qualities q where q.id = lots.quality_id)'),
        ]);
    }
};
