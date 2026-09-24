<?php

use App\Modules\OracleSync\Source\OracleSourceConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The mocked Oracle source schema, on the `oracle_src` connection.
 *
 * It creates NOTHING unless `oracle_src` is a local stand-in (OracleSourceConnection::isMocked()). The moment
 * the connection points at a real Oracle instance this migration is a no-op, because the application must
 * never run DDL against the mill's ERP — it reads from it.
 *
 * Shapes follow docs/planning/findings.md §2 as closely as MySQL allows:
 *   - Oracle NUMBER(n) with no scale is an integer here: quantities really are whole metres (§6 q.12).
 *   - Oracle DATE carries a time component, so every DATE is a MySQL DATETIME.
 *   - LOT_NUM is indexed but NOT unique (§5.1); PC_DTL_ID is the reliable lot key.
 *   - Oracle supplied no keys or constraints at all (§1). The primary keys below are the mock's own, chosen on
 *     the columns findings.md names as row keys, so that the stand-in behaves; do not read them as Oracle facts.
 *   - PROCESSING_CONTRACT_D was never supplied (§4.3); its columns are inferred from the Excel export the
 *     existing importer consumes, and it is marked as a stand-in wherever it is referenced.
 */
return new class extends Migration
{
    protected $connection = OracleSourceConnection::NAME;

    public function up(): void
    {
        if (! OracleSourceConnection::isMocked()) {
            return;
        }

        $schema = Schema::connection($this->connection);

        // --- transactions -------------------------------------------------------------------------------

        $schema->create('PROCESSING_LOTS', function (Blueprint $t) {
            $t->unsignedBigInteger('PROCESSING_LOT_ID')->primary();   // NUMBER(10)
            $t->unsignedInteger('LOT_NUM');                            // NUMBER(8) — not unique
            $t->string('PLOT_NUM', 30)->nullable();                    // batch/run id — unconfirmed (§6 q.9)
            $t->unsignedInteger('CONTRACT_NUM');
            $t->unsignedInteger('PARTY_CODE');
            $t->unsignedInteger('Q_CODE');
            $t->string('REF_NUM', 25)->nullable();
            $t->dateTime('DATED');
            $t->unsignedInteger('CC_CODE');                            // where the work happened
            $t->unsignedInteger('TO_CC_CODE');                         // where the lot went next — the movement
            $t->unsignedInteger('MACHINE_CODE');
            $t->unsignedInteger('OPERATION_CODE')->nullable();         // nullable in Oracle (§2.3)
            $t->unsignedInteger('QNTY_USED');                          // integer metres
            $t->unsignedInteger('QNTY_PRODUCED');                      // integer metres
            $t->decimal('G_WIDTH', 8, 2)->nullable();
            $t->decimal('F_WIDTH', 8, 2)->nullable();
            $t->decimal('F_WIDTH_PRODUCED', 8, 2)->nullable();
            $t->string('PROCESS_TYPE', 20);
            $t->unsignedInteger('DESIGN_CODE')->nullable();
            $t->unsignedInteger('VERSION_CODE')->nullable();
            $t->unsignedInteger('COLOUR_CODE')->nullable();
            $t->dateTime('LOT_STARTTIME')->nullable();
            $t->dateTime('LOT_ENDTIME')->nullable();
            $t->string('REPROCESS', 1)->nullable();
            $t->string('NON_PROCESS', 1)->nullable();                  // meaning unknown (§6 q.7)
            $t->string('GREIGE_RECEIPT', 1)->nullable();
            $t->string('GREIGE_RECEIPT_BY', 30)->nullable();
            $t->dateTime('GREIGE_RECEIPT_DT')->nullable();
            $t->dateTime('ENTRY_DATE')->nullable();
            $t->string('USER_ID', 15)->nullable();
            $t->string('COMPUTER', 15)->nullable();
            $t->string('REMARKS', 100)->nullable();
            $t->index(['LOT_NUM', 'CONTRACT_NUM'], 'PROCESSING_LOTS_LOT_CONTRACT_IDX');
            $t->index(['DATED'], 'PROCESSING_LOTS_DATED_IDX');
            $t->index(['CC_CODE', 'DATED'], 'PROCESSING_LOTS_CC_IDX');
            $t->index(['TO_CC_CODE', 'DATED'], 'PROCESSING_LOTS_TO_CC_IDX');
        });

        $schema->create('GREIGE_ISSUE_M', function (Blueprint $t) {
            $t->unsignedInteger('GI_MAIN_ID')->primary();
            $t->unsignedInteger('GI_NUM');
            $t->dateTime('DATED');
            $t->string('REF_NUM', 8)->nullable();
            $t->string('SECTION_CODE', 4)->nullable();                 // meaning unknown (§6 q.10)
            $t->string('REMARKS', 150)->nullable();
            $t->string('FINAL', 1)->nullable();                        // document status, not lot status
            $t->dateTime('FINAL_DATE')->nullable();
            $t->string('FINAL_BY', 15)->nullable();
            $t->dateTime('ENTRY_DATE')->nullable();
            $t->string('USER_ID', 15)->nullable();
            $t->string('COMPUTER', 15)->nullable();
            $t->unsignedInteger('CC_CODE')->default(82);               // the only default Oracle supplied
            $t->index(['DATED'], 'GREIGE_ISSUE_M_DATED_IDX');
        });

        $schema->create('GREIGE_ISSUE_D', function (Blueprint $t) {
            $t->unsignedInteger('GI_MAIN_ID');
            $t->unsignedInteger('GI_DTL_ID');
            $t->unsignedInteger('LOT_NUM');                            // not unique on its own (§5.1)
            $t->unsignedInteger('PC_MAIN_ID')->nullable();
            $t->unsignedInteger('PC_DTL_ID');                          // the reliable lot key
            $t->unsignedInteger('CONTRACT_NUM');
            $t->unsignedInteger('PARTY_CODE');
            $t->unsignedInteger('Q_CODE');
            $t->decimal('G_WIDTH', 5, 2)->nullable();
            $t->unsignedInteger('QNTY');                               // integer metres
            $t->decimal('RATE', 6, 2)->nullable();
            $t->unsignedInteger('THAN')->nullable();
            $t->string('LOCATION', 15)->nullable();
            $t->unsignedInteger('GSM')->nullable();
            $t->primary(['GI_MAIN_ID', 'GI_DTL_ID']);
            $t->index(['PC_DTL_ID'], 'GREIGE_ISSUE_D_PC_DTL_IDX');
            $t->index(['LOT_NUM', 'CONTRACT_NUM'], 'GREIGE_ISSUE_D_LOT_CONTRACT_IDX');
        });

        // Stand-in: the real PROCESSING_CONTRACT_D DDL was never supplied (findings.md §4.3).
        $schema->create('PROCESSING_CONTRACT_D', function (Blueprint $t) {
            $t->unsignedInteger('PC_DTL_ID')->primary();
            $t->unsignedInteger('PC_MAIN_ID')->nullable();
            $t->unsignedInteger('CONTRACT_NUM');
            $t->unsignedInteger('PARTY_CODE');
            $t->unsignedInteger('Q_CODE');
            $t->unsignedInteger('LOT_NUM');
            $t->string('REF_NUM', 25)->nullable();
            $t->dateTime('DATED');
            $t->unsignedInteger('GSM')->nullable();
            $t->unsignedInteger('DESIGN_CODE')->nullable();
            $t->unsignedInteger('VERSION_CODE')->nullable();
            $t->unsignedInteger('COLOUR_CODE')->nullable();
            $t->decimal('G_WIDTH', 5, 2)->nullable();
            $t->decimal('F_WIDTH', 5, 2)->nullable();
            $t->string('PROCESS_TYPE', 20);
            $t->unsignedInteger('QNTY');                               // lot quantity, integer metres
            $t->unsignedInteger('QNTY_CONT');                          // contract quantity, integer metres
            $t->index(['CONTRACT_NUM'], 'PROCESSING_CONTRACT_D_CONTRACT_IDX');
            $t->index(['LOT_NUM', 'CONTRACT_NUM'], 'PROCESSING_CONTRACT_D_LOT_IDX');
        });

        // --- masters (findings.md §4.1: every one of these is missing from what Oracle supplied) ----------

        $schema->create('COST_CENTRE_M', function (Blueprint $t) {
            $t->unsignedInteger('CC_CODE')->primary();
            $t->string('CC_NAME', 60);
            $t->string('CC_SHORT', 15)->nullable();
            $t->string('IS_ACTIVE', 1)->default('Y');
        });

        $schema->create('MACHINE_M', function (Blueprint $t) {
            $t->unsignedInteger('MACHINE_CODE')->primary();
            $t->string('MACHINE_NAME', 60);
            $t->unsignedInteger('CC_CODE')->nullable();
            $t->string('IS_ACTIVE', 1)->default('Y');
        });

        $schema->create('OPERATION_M', function (Blueprint $t) {
            $t->unsignedInteger('OPERATION_CODE')->primary();
            $t->string('OPERATION_NAME', 60);
            $t->unsignedInteger('CC_CODE')->nullable();
            $t->string('IS_ACTIVE', 1)->default('Y');
        });

        $schema->create('DESIGN_M', function (Blueprint $t) {
            $t->unsignedInteger('DESIGN_CODE')->primary();
            $t->string('DESIGN_NAME', 60);
        });

        $schema->create('COLOUR_M', function (Blueprint $t) {
            $t->unsignedInteger('COLOUR_CODE')->primary();
            $t->string('COLOUR_NAME', 80);
        });

        $schema->create('PARTY_M', function (Blueprint $t) {
            $t->unsignedInteger('PARTY_CODE')->primary();
            $t->string('PARTY_NAME', 100);
        });

        $schema->create('QUALITY_M', function (Blueprint $t) {
            $t->unsignedInteger('Q_CODE')->primary();
            $t->string('QUALITY_NAME', 150);
        });
    }

    public function down(): void
    {
        if (! OracleSourceConnection::isMocked()) {
            return;
        }

        $schema = Schema::connection($this->connection);

        foreach ([
            'QUALITY_M', 'PARTY_M', 'COLOUR_M', 'DESIGN_M', 'OPERATION_M', 'MACHINE_M', 'COST_CENTRE_M',
            'PROCESSING_CONTRACT_D', 'GREIGE_ISSUE_D', 'GREIGE_ISSUE_M', 'PROCESSING_LOTS',
        ] as $table) {
            $schema->dropIfExists($table);
        }
    }
};
