<?php

use App\Modules\OracleSync\Source\Models\CostCentre;
use App\Modules\OracleSync\Source\Models\GreigeIssueHeader;
use App\Modules\OracleSync\Source\Models\GreigeIssueLine;
use App\Modules\OracleSync\Source\Models\OracleColour;
use App\Modules\OracleSync\Source\Models\OracleDesign;
use App\Modules\OracleSync\Source\Models\OracleOperation;
use App\Modules\OracleSync\Source\Models\OracleParty;
use App\Modules\OracleSync\Source\Models\OracleQuality;
use App\Modules\OracleSync\Source\Models\ProcessingContractHeader;
use App\Modules\OracleSync\Source\Models\ProcessingContractLine;
use App\Modules\OracleSync\Source\Models\ProcessingContractLot;
use App\Modules\OracleSync\Source\Models\ProcessingLot;
use App\Modules\OracleSync\Source\OracleSourceConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brings the mocked Oracle source schema in line with the instance it stands in for.
 *
 * 2026_09_24_000400 described a schema that does not exist. Its shapes came from DDL text for three tables
 * plus inference from an Excel export for everything else, and on 2026-10-31 the real objects were read
 * from the mill's instance over a read-only connection (docs/planning/findings.md §9). What changed:
 *
 *   - the masters are in the **ERP** schema under different names: `PARTY_M` → `PARTIES`, `QUALITY_M` →
 *     `QUALITIES`, `DESIGN_M` → `DESIGNS`, `COLOUR_M` → `COLOURS`, `OPERATION_M` →
 *     `PROCESSING_OPERATIONS`, `COST_CENTRE_M` → `COST_CENTRES`;
 *   - `MACHINE_M` does not exist — machines are rows in `COST_CENTRES` with `TYPE = 'MACHINE'`;
 *   - `QUALITY_NAME` → `DESCRIPTION`, `CC_NAME` → `COST_CENTRE`, and `CC_SHORT` / `IS_ACTIVE` are gone
 *     (`COST_CENTRES` has `CLOSED`, inverted; `PROCESSING_OPERATIONS` has no flag at all);
 *   - `PROCESSING_LOTS` has no `PLOT_NUM` and does have `PC_DTL_ID`;
 *   - contracts are master/detail: `PROCESSING_CONTRACT_M` holds `CONTRACT_NUM`, `DATED`, `PARTY_CODE` and
 *     `REF_NUM`, `PROCESSING_CONTRACT_D` has `QNTY` and no `QNTY_CONT`, and `GSM` is on neither — it is on
 *     the third table, `PC_DTL_LOTS`.
 *
 * The guards are the earlier migration's, unchanged and for the same reasons: it creates and drops NOTHING
 * unless `oracle_src` is a local stand-in (the application must never run DDL against the mill's ERP — it
 * reads from it) and nothing when `APP_ENV=production` (the default `oracle_src` is the application's own
 * database, so on a live install this would otherwise drop and create Oracle-shaped tables next to `lots`
 * and `users`).
 *
 * The tables are dropped and recreated rather than altered. The stand-in holds nothing but
 * OracleMockSeeder's fixtures, so there is no data to preserve, and half these tables change name anyway.
 * After a plain `migrate` on an existing dev install the mock is therefore **empty** until
 * `php artisan db:seed --class=Database\\Seeders\\OracleMockSeeder` is run (migrate:fresh --seed does it).
 *
 * Shapes follow the live dictionary, as closely as MySQL and SQLite allow:
 *   - Oracle NUMBER(n) with no scale is an integer here: quantities really are whole metres (findings.md
 *     §6 q.12 — and that is Oracle's doing, not this application's decimal(12,2) convention).
 *   - Oracle DATE carries a time component, so every DATE is a DATETIME (never a TIMESTAMP).
 *   - Nullability mirrors the dictionary, so a column that is optional in Oracle is optional here.
 *   - LOT_NUM is indexed but NOT unique (§5.1); PC_DTL_ID is the reliable lot key.
 *   - The primary keys are the mock's own. Oracle declares no key on any of these tables; `PARTY_CODE`,
 *     `Q_CODE` and `CC_CODE` are even nullable there, though they are in fact distinct in every row. They
 *     are keys here so the stand-in behaves; do not read them as Oracle facts. `PROCESSING_OPERATIONS`
 *     gets no primary key at all, because nothing about it is unique — see OracleOperation.
 */
return new class extends Migration
{
    protected $connection = OracleSourceConnection::NAME;

    /** The tables 2026_09_24_000400 created, in drop order. */
    private const SUPERSEDED = [
        'QUALITY_M', 'PARTY_M', 'COLOUR_M', 'DESIGN_M', 'OPERATION_M', 'MACHINE_M', 'COST_CENTRE_M',
        'PROCESSING_CONTRACT_D', 'GREIGE_ISSUE_D', 'GREIGE_ISSUE_M', 'PROCESSING_LOTS',
    ];

    public function up(): void
    {
        if (! $this->mayReshapeTheMock()) {
            return;
        }

        $this->dropAll(self::SUPERSEDED);
        $this->dropAll($this->currentTables());

        $schema = Schema::connection($this->connection);

        // --- PRODUCTION: transactions ---------------------------------------------------------------

        $schema->create(ProcessingLot::TABLE, function (Blueprint $t) {
            $t->unsignedBigInteger('PROCESSING_LOT_ID')->primary();   // NUMBER(10)
            $t->unsignedInteger('CC_CODE');                            // where the work happened
            $t->unsignedInteger('MACHINE_CODE');                       // a COST_CENTRES row, TYPE = MACHINE
            $t->string('REF_NUM', 25)->nullable();
            $t->unsignedInteger('LOT_NUM');                            // NUMBER(8) — not unique
            $t->unsignedInteger('PC_DTL_ID')->nullable();              // the contract line; the reliable key
            $t->unsignedInteger('CONTRACT_NUM');
            $t->unsignedInteger('PARTY_CODE');
            $t->dateTime('DATED')->nullable();
            $t->unsignedInteger('Q_CODE');
            $t->decimal('G_WIDTH', 8, 2);
            $t->decimal('F_WIDTH', 8, 2)->nullable();
            $t->string('PROCESS_TYPE', 20);
            $t->unsignedInteger('DESIGN_CODE')->nullable();
            $t->unsignedInteger('VERSION_CODE')->nullable();
            $t->unsignedInteger('COLOUR_CODE')->nullable();
            $t->unsignedInteger('QNTY_USED');                          // integer metres
            $t->unsignedInteger('QNTY_PRODUCED');                      // integer metres
            $t->string('REMARKS', 100)->nullable();
            $t->unsignedInteger('TO_CC_CODE');                         // where the lot went next
            $t->unsignedInteger('OPERATION_CODE')->nullable();         // nullable in Oracle (§2.3)
            $t->dateTime('ENTRY_DATE')->nullable();
            $t->string('USER_ID', 30)->nullable();
            $t->string('COMPUTER', 30)->nullable();
            $t->dateTime('LOT_STARTTIME')->nullable();
            $t->dateTime('LOT_ENDTIME')->nullable();
            $t->decimal('F_WIDTH_PRODUCED', 8, 2)->nullable();
            $t->string('REPROCESS', 1)->nullable();
            $t->string('NON_PROCESS', 1)->nullable();                  // meaning unknown (§6 q.7)
            $t->string('GREIGE_RECEIPT', 1)->nullable();
            $t->string('GREIGE_RECEIPT_BY', 30)->nullable();
            $t->dateTime('GREIGE_RECEIPT_DT')->nullable();
            $t->index(['LOT_NUM', 'CONTRACT_NUM'], 'PROCESSING_LOTS_LOT_CONTRACT_IDX');
            $t->index(['PC_DTL_ID'], 'PROCESSING_LOTS_PC_DTL_IDX');
            $t->index(['DATED'], 'PROCESSING_LOTS_DATED_IDX');
            $t->index(['CC_CODE', 'DATED'], 'PROCESSING_LOTS_CC_IDX');
            $t->index(['TO_CC_CODE', 'DATED'], 'PROCESSING_LOTS_TO_CC_IDX');
        });

        $schema->create(GreigeIssueHeader::TABLE, function (Blueprint $t) {
            $t->unsignedInteger('GI_MAIN_ID')->primary();
            $t->unsignedInteger('GI_NUM')->nullable();
            $t->dateTime('DATED')->nullable();
            $t->string('REF_NUM', 8)->nullable();
            $t->string('SECTION_CODE', 4)->nullable();                 // meaning unknown (§6 q.10)
            $t->string('REMARKS', 150)->nullable();
            $t->string('FINAL', 1)->nullable();                        // document status, not lot status
            $t->dateTime('FINAL_DATE')->nullable();
            $t->string('FINAL_BY', 15)->nullable();
            $t->dateTime('ENTRY_DATE')->nullable();
            $t->string('USER_ID', 15)->nullable();
            $t->string('COMPUTER', 15)->nullable();
            $t->unsignedInteger('CC_CODE')->nullable()->default(82);   // the only default Oracle supplied
            $t->index(['DATED'], 'GREIGE_ISSUE_M_DATED_IDX');
        });

        $schema->create(GreigeIssueLine::TABLE, function (Blueprint $t) {
            $t->unsignedInteger('GI_MAIN_ID')->nullable();
            $t->unsignedInteger('LOT_NUM')->nullable();                // not unique on its own (§5.1)
            $t->unsignedInteger('GI_DTL_ID');
            $t->unsignedInteger('Q_CODE')->nullable();
            $t->decimal('G_WIDTH', 5, 2)->nullable();
            $t->unsignedInteger('PARTY_CODE')->nullable();
            $t->unsignedInteger('QNTY')->nullable();                   // integer metres
            $t->decimal('RATE', 6, 2)->nullable();
            $t->unsignedInteger('THAN')->nullable();
            $t->unsignedInteger('CONTRACT_NUM')->nullable();
            $t->unsignedInteger('PC_MAIN_ID')->nullable();
            $t->string('LOCATION', 15)->nullable();
            $t->unsignedInteger('GSM')->nullable();
            $t->unsignedInteger('PC_DTL_ID')->nullable();              // the reliable lot key
            $t->primary(['GI_MAIN_ID', 'GI_DTL_ID']);
            $t->index(['PC_DTL_ID'], 'GREIGE_ISSUE_D_PC_DTL_IDX');
            $t->index(['LOT_NUM', 'CONTRACT_NUM'], 'GREIGE_ISSUE_D_LOT_CONTRACT_IDX');
        });

        $schema->create(ProcessingContractHeader::TABLE, function (Blueprint $t) {
            $t->unsignedInteger('PC_MAIN_ID')->primary();
            $t->unsignedInteger('CONTRACT_NUM')->nullable();           // NOT unique across headers
            $t->dateTime('DATED')->nullable();
            $t->unsignedInteger('PARTY_CODE')->nullable();
            $t->string('WITH_INV', 1)->nullable();
            $t->string('REMARKS', 200)->nullable();
            $t->string('APPROVED', 1)->nullable();
            $t->string('APPROVED_BY', 25)->nullable();
            $t->dateTime('APPROVED_DATE')->nullable();
            $t->dateTime('ENTRY_DATE')->nullable();
            $t->string('USER_ID', 25)->nullable();
            $t->string('COMPUTER', 30)->nullable();
            $t->string('FOLDED_TYPE', 15)->nullable();
            $t->string('CHECKED', 1)->nullable();
            $t->string('CHECKED_BY', 25)->nullable();
            $t->dateTime('CHECKED_DATE')->nullable();
            $t->string('CLOSED', 1)->nullable();
            $t->string('CLOSED_BY', 25)->nullable();
            $t->dateTime('CLOSED_DATE')->nullable();
            $t->decimal('PRINT_CHRGS', 8, 4)->nullable();
            $t->string('REF_NUM', 25)->nullable();
            $t->decimal('STAX_RATE', 4, 2)->nullable();
            $t->string('REVISION', 20)->nullable();
            $t->string('NATURE', 15)->nullable();
            $t->string('PACKING_TYPE', 20)->nullable();
            $t->string('REPROCESSES', 1)->nullable();
            $t->string('CATEGORY', 15)->nullable();
            $t->index(['CONTRACT_NUM'], 'PROCESSING_CONTRACT_M_CONTRACT_IDX');
            $t->index(['DATED'], 'PROCESSING_CONTRACT_M_DATED_IDX');
        });

        $schema->create(ProcessingContractLine::TABLE, function (Blueprint $t) {
            $t->unsignedInteger('PC_MAIN_ID')->nullable();
            $t->unsignedInteger('PC_DTL_ID')->primary();
            $t->unsignedInteger('Q_CODE')->nullable();
            $t->string('WIDTH_UOM', 4)->nullable();
            $t->decimal('G_WIDTH', 5, 2)->nullable();
            $t->unsignedInteger('F_WIDTH')->nullable();                // NUMBER(3), no scale
            $t->string('PROCESS_TYPE', 20)->nullable();
            $t->unsignedInteger('DESIGN_CODE')->nullable();
            $t->unsignedInteger('VERSION_CODE')->nullable();
            $t->unsignedInteger('COLOUR_CODE')->nullable();
            $t->unsignedInteger('QNTY')->nullable();                   // the line quantity; no QNTY_CONT
            $t->string('FINISH_TYPE', 15)->nullable();
            $t->decimal('RATE', 5, 2)->nullable();
            $t->decimal('RATE_BG', 5, 2)->nullable();
            $t->unsignedInteger('LOT_NUM')->nullable();
            $t->string('REMARKS', 500)->nullable();
            $t->dateTime('SHADE_REF_DT')->nullable();
            $t->dateTime('DIPS_DATE')->nullable();
            $t->dateTime('APPROVED_DT')->nullable();
            $t->string('CLOSED', 1)->nullable();
            $t->string('LAB_TEST', 1)->nullable();
            $t->dateTime('CLOSED_DATE')->nullable();
            $t->string('COLOUR_SHADE', 15)->nullable();
            $t->string('TRICHROME_APP_BY', 15)->nullable();
            $t->string('APPROVED_REMARKS', 250)->nullable();
            $t->decimal('PCT_A', 3, 1)->nullable();
            $t->unsignedInteger('QNTY_A')->nullable();
            $t->decimal('PCT_B', 2, 1)->nullable();
            $t->unsignedInteger('QNTY_B')->nullable();
            $t->decimal('PCT_C', 2, 1)->nullable();
            $t->unsignedInteger('QNTY_C')->nullable();
            $t->index(['PC_MAIN_ID'], 'PROCESSING_CONTRACT_D_MAIN_IDX');
            $t->index(['LOT_NUM'], 'PROCESSING_CONTRACT_D_LOT_IDX');
        });

        $schema->create(ProcessingContractLot::TABLE, function (Blueprint $t) {
            $t->unsignedInteger('PC_MAIN_ID')->nullable();
            $t->unsignedInteger('PC_DTL_ID')->nullable();
            $t->unsignedInteger('PCL_DTL_ID')->primary();
            $t->unsignedInteger('Q_CODE')->nullable();
            $t->decimal('G_WIDTH', 5, 2)->nullable();
            $t->unsignedInteger('QNTY')->nullable();
            $t->unsignedInteger('LOT_NUM')->nullable();
            $t->unsignedInteger('GSM')->nullable();                    // the only place GSM is recorded
            // Not unique: several rows per contract line, and per (line, lot) — hence the subselect in
            // OracleSourceReader rather than a join.
            $t->index(['PC_DTL_ID', 'LOT_NUM'], 'PC_DTL_LOTS_DTL_LOT_IDX');
        });

        // --- ERP: the code masters --------------------------------------------------------------------

        $schema->create(CostCentre::TABLE, function (Blueprint $t) {
            $t->unsignedInteger('CC_CODE')->primary();
            $t->string('COST_CENTRE', 100);                            // was CC_NAME; no CC_SHORT exists
            $t->string('TYPE', 10)->nullable();                        // 'MACHINE' for the machine rows
            $t->string('LOCATION', 15)->nullable();
            $t->dateTime('CREATION_DATE')->nullable();
            $t->string('REMARKS', 100)->nullable();
            $t->string('CLOSED', 1)->nullable();                       // inverted; NULL and 'N' mean open
            $t->index(['TYPE'], 'COST_CENTRES_TYPE_IDX');
        });

        $schema->create(OracleOperation::TABLE, function (Blueprint $t) {
            // No primary key: 92 rows, 89 distinct OPERATION_CODEs, and (CC_CODE, OPERATION_CODE) is not
            // unique either. See OracleOperation.
            $t->unsignedInteger('CC_CODE')->nullable();
            $t->unsignedInteger('OPERATION_CODE')->nullable();
            $t->string('OPERATION_NAME', 150)->nullable();
            $t->string('OPERATION_TYPE', 15)->nullable();
            $t->index(['CC_CODE', 'OPERATION_CODE'], 'PROCESSING_OPERATIONS_CC_OP_IDX');
        });

        $schema->create(OracleDesign::TABLE, function (Blueprint $t) {
            $t->unsignedInteger('DESIGN_CODE')->primary();
            $t->string('DESIGN_NAME', 50)->nullable();
        });

        $schema->create(OracleColour::TABLE, function (Blueprint $t) {
            $t->unsignedInteger('COLOUR_CODE')->primary();
            $t->string('COLOUR_NAME', 50)->nullable();
        });

        $schema->create(OracleQuality::TABLE, function (Blueprint $t) {
            $t->unsignedInteger('Q_CODE')->primary();
            $t->unsignedInteger('REED')->nullable();
            $t->unsignedInteger('PICK')->nullable();
            $t->decimal('WARP', 5, 2)->nullable();
            $t->decimal('WEFT', 5, 2)->nullable();
            $t->string('LOOM_TYPE', 2)->nullable();
            $t->string('BLEND', 15)->nullable();
            $t->unsignedInteger('BLEND_RATIO1')->nullable();
            $t->unsignedInteger('BLEND_RATIO2')->nullable();
            $t->string('FAB_TYPE', 10)->nullable();
            $t->string('DESCRIPTION', 50)->nullable();                 // was QUALITY_NAME
            $t->string('APPROVED', 1)->nullable();
            $t->string('WARP_TYPE', 5)->nullable();
            $t->string('WEFT_TYPE', 5)->nullable();
        });

        $schema->create(OracleParty::TABLE, function (Blueprint $t) {
            $t->unsignedInteger('PARTY_CODE')->primary();
            $t->string('PARTY_NAME', 100)->nullable();
            $t->string('PARTY_TYPE', 20)->nullable();
            $t->string('PARTY_SUB_TYPE', 100)->nullable();
            $t->string('SHORT_NAME', 10)->nullable();
            $t->string('CARE_OF', 100)->nullable();
            $t->string('PARTY_OTHER_NAMES', 200)->nullable();
            $t->string('PARTY_OTHER_CLIENTS', 200)->nullable();
            $t->string('ADDRESS', 200)->nullable();
            $t->string('DELIVERY_ADDRESS', 200)->nullable();
            $t->string('BILLING_ADDRESS', 200)->nullable();
            $t->string('CITY', 30)->nullable();
            $t->string('TAX_PAYER_STATUS', 30)->nullable();
            $t->string('STATUS', 10)->nullable();
            $t->unsignedInteger('COUNTRY_CODE')->nullable();
            $t->string('CONTACT_PERSON', 50)->nullable();
            $t->string('PHONE', 50)->nullable();
            $t->string('MOBILE', 50)->nullable();
            $t->string('FAX', 30)->nullable();
            $t->string('EMAIL', 50)->nullable();
            $t->string('DEALS_IN', 50)->nullable();
            $t->string('STRN', 20)->nullable();
            $t->string('NTN', 25)->nullable();
            $t->string('CNIC', 15)->nullable();
            $t->string('PARTY_NAME_NTN', 100)->nullable();
            $t->string('ACC_CODE_01', 10)->nullable();
            $t->string('ACC_CODE_02', 10)->nullable();
            $t->string('ACC_CODE_STAX', 10)->nullable();
            $t->string('ACC_CODE_COMM', 10)->nullable();
            $t->dateTime('ENTRY_DATE')->nullable();
            $t->string('COMPUTER', 30)->nullable();
            $t->string('USER_ID', 35)->nullable();
            $t->unsignedInteger('PAYMENT_DAYS')->nullable();
            $t->string('PDAYS', 8)->nullable();
            $t->decimal('COMM_RATE', 4, 2)->nullable();
        });
    }

    /**
     * Back to the schema 2026_09_24_000400 left behind: drop everything this migration created and put the
     * superseded shapes back, so `migrate:rollback` lands on a stand-in that still works (the mock holds no
     * data worth preserving either way — OracleMockSeeder refills it).
     *
     * The DDL below is a deliberate second copy of that migration's, not a call into it: replaying another
     * migration object's up() from here would be an untyped dynamic call on
     * Illuminate\Database\Migrations\Migration, which has no up(). The copy is frozen history — the
     * superseded migration will never change again — and only a rollback ever reads it.
     */
    public function down(): void
    {
        if (! $this->mayReshapeTheMock()) {
            return;
        }

        $this->dropAll($this->currentTables());

        $schema = Schema::connection($this->connection);

        $schema->create('PROCESSING_LOTS', function (Blueprint $t) {
            $t->unsignedBigInteger('PROCESSING_LOT_ID')->primary();
            $t->unsignedInteger('LOT_NUM');
            $t->string('PLOT_NUM', 30)->nullable();
            $t->unsignedInteger('CONTRACT_NUM');
            $t->unsignedInteger('PARTY_CODE');
            $t->unsignedInteger('Q_CODE');
            $t->string('REF_NUM', 25)->nullable();
            $t->dateTime('DATED');
            $t->unsignedInteger('CC_CODE');
            $t->unsignedInteger('TO_CC_CODE');
            $t->unsignedInteger('MACHINE_CODE');
            $t->unsignedInteger('OPERATION_CODE')->nullable();
            $t->unsignedInteger('QNTY_USED');
            $t->unsignedInteger('QNTY_PRODUCED');
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
            $t->string('NON_PROCESS', 1)->nullable();
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
            $t->string('SECTION_CODE', 4)->nullable();
            $t->string('REMARKS', 150)->nullable();
            $t->string('FINAL', 1)->nullable();
            $t->dateTime('FINAL_DATE')->nullable();
            $t->string('FINAL_BY', 15)->nullable();
            $t->dateTime('ENTRY_DATE')->nullable();
            $t->string('USER_ID', 15)->nullable();
            $t->string('COMPUTER', 15)->nullable();
            $t->unsignedInteger('CC_CODE')->default(82);
            $t->index(['DATED'], 'GREIGE_ISSUE_M_DATED_IDX');
        });

        $schema->create('GREIGE_ISSUE_D', function (Blueprint $t) {
            $t->unsignedInteger('GI_MAIN_ID');
            $t->unsignedInteger('GI_DTL_ID');
            $t->unsignedInteger('LOT_NUM');
            $t->unsignedInteger('PC_MAIN_ID')->nullable();
            $t->unsignedInteger('PC_DTL_ID');
            $t->unsignedInteger('CONTRACT_NUM');
            $t->unsignedInteger('PARTY_CODE');
            $t->unsignedInteger('Q_CODE');
            $t->decimal('G_WIDTH', 5, 2)->nullable();
            $t->unsignedInteger('QNTY');
            $t->decimal('RATE', 6, 2)->nullable();
            $t->unsignedInteger('THAN')->nullable();
            $t->string('LOCATION', 15)->nullable();
            $t->unsignedInteger('GSM')->nullable();
            $t->primary(['GI_MAIN_ID', 'GI_DTL_ID']);
            $t->index(['PC_DTL_ID'], 'GREIGE_ISSUE_D_PC_DTL_IDX');
            $t->index(['LOT_NUM', 'CONTRACT_NUM'], 'GREIGE_ISSUE_D_LOT_CONTRACT_IDX');
        });

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
            $t->unsignedInteger('QNTY');
            $t->unsignedInteger('QNTY_CONT');
            $t->index(['CONTRACT_NUM'], 'PROCESSING_CONTRACT_D_CONTRACT_IDX');
            $t->index(['LOT_NUM', 'CONTRACT_NUM'], 'PROCESSING_CONTRACT_D_LOT_IDX');
        });

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

    /**
     * Drops nothing against a real Oracle instance, and nothing on a production box. Identical to the
     * guard on 2026_09_24_000400 and for identical reasons.
     */
    private function mayReshapeTheMock(): bool
    {
        return OracleSourceConnection::isMocked() && config('app.env') !== 'production';
    }

    /** @return list<string> the tables this migration owns, in drop order */
    private function currentTables(): array
    {
        return [
            OracleParty::TABLE, OracleQuality::TABLE, OracleColour::TABLE, OracleDesign::TABLE,
            OracleOperation::TABLE, CostCentre::TABLE,
            ProcessingContractLot::TABLE, ProcessingContractLine::TABLE, ProcessingContractHeader::TABLE,
            GreigeIssueLine::TABLE, GreigeIssueHeader::TABLE, ProcessingLot::TABLE,
        ];
    }

    /** @param  list<string>  $tables */
    private function dropAll(array $tables): void
    {
        $schema = Schema::connection($this->connection);

        foreach ($tables as $table) {
            $schema->dropIfExists($table);
        }
    }
};
