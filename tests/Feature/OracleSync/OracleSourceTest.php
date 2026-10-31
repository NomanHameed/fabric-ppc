<?php

use App\Modules\OracleSync\Source\Models\CostCentre;
use App\Modules\OracleSync\Source\Models\GreigeIssueLine;
use App\Modules\OracleSync\Source\Models\OracleOperation;
use App\Modules\OracleSync\Source\Models\ProcessingContractHeader;
use App\Modules\OracleSync\Source\Models\ProcessingContractLine;
use App\Modules\OracleSync\Source\Models\ProcessingContractLot;
use App\Modules\OracleSync\Source\Models\ProcessingLot;
use App\Modules\OracleSync\Source\OracleSourceConnection;
use App\Modules\OracleSync\Source\OracleSourceReader;
use App\Modules\OracleSync\Source\SourceModel;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\LotMovement;
use Database\Seeders\OracleMockSeeder;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->seed(OracleMockSeeder::class);
    $this->reader = app(OracleSourceReader::class);
});

/** @return list<class-string<SourceModel>> every source model, so nothing escapes the guard tests */
function sourceModelClasses(): array
{
    $classes = [];

    foreach ((array) glob(app_path('Modules/OracleSync/Source/Models/*.php')) as $file) {
        $class = 'App\\Modules\\OracleSync\\Source\\Models\\'.basename((string) $file, '.php');
        if (class_exists($class) && is_subclass_of($class, SourceModel::class)) {
            $classes[] = $class;
        }
    }

    sort($classes);

    return $classes;
}

/*
 * Honest name: this proves the tables exist *on the oracle_src connection* and that the source models read
 * through it. It does NOT prove they are in a different database — with the default stand-in configuration
 * `oracle_src` deliberately points at the same schema as the app connection (config/database.php), so the
 * Oracle-shaped tables really do sit beside `lots` and `users` on a development install. On SQLite `:memory:`
 * each connection gets a private database, which made an earlier version of this test look like proof of a
 * separation the MySQL configuration does not have.
 */
it('creates the Oracle-shaped tables on the oracle_src connection', function () {
    $oracle = Schema::connection(OracleSourceConnection::NAME);

    foreach ([
        // PRODUCTION
        'PROCESSING_LOTS', 'GREIGE_ISSUE_M', 'GREIGE_ISSUE_D',
        'PROCESSING_CONTRACT_M', 'PROCESSING_CONTRACT_D', 'PC_DTL_LOTS',
        // ERP
        'COST_CENTRES', 'PROCESSING_OPERATIONS', 'DESIGNS', 'COLOURS', 'PARTIES', 'QUALITIES',
    ] as $table) {
        expect($oracle->hasTable($table))->toBeTrue("{$table} exists on the source connection");
    }

    // The superseded shapes are gone, including MACHINE_M: machines are cost centres, not a master of
    // their own (CostCentre).
    foreach (['MACHINE_M', 'COST_CENTRE_M', 'PARTY_M', 'QUALITY_M', 'DESIGN_M', 'COLOUR_M', 'OPERATION_M'] as $table) {
        expect($oracle->hasTable($table))->toBeFalse("{$table} no longer exists");
    }

    expect(CostCentre::query()->getModel()->getConnectionName())->toBe(OracleSourceConnection::NAME);
});

it('carries the columns the live instance has, and not the ones it does not', function () {
    $oracle = Schema::connection(OracleSourceConnection::NAME);

    // PLOT_NUM was inferred and does not exist; PC_DTL_ID does (findings.md §6 q.9, answered 2026-10-31).
    expect($oracle->hasColumn('PROCESSING_LOTS', 'PC_DTL_ID'))->toBeTrue()
        ->and($oracle->hasColumn('PROCESSING_LOTS', 'PLOT_NUM'))->toBeFalse();

    // Contracts are master/detail, and GSM is on neither of them.
    expect($oracle->hasColumn('PROCESSING_CONTRACT_M', 'CONTRACT_NUM'))->toBeTrue()
        ->and($oracle->hasColumn('PROCESSING_CONTRACT_D', 'CONTRACT_NUM'))->toBeFalse()
        ->and($oracle->hasColumn('PROCESSING_CONTRACT_D', 'QNTY'))->toBeTrue()
        ->and($oracle->hasColumn('PROCESSING_CONTRACT_D', 'QNTY_CONT'))->toBeFalse()
        ->and($oracle->hasColumn('PROCESSING_CONTRACT_D', 'GSM'))->toBeFalse()
        ->and($oracle->hasColumn('PC_DTL_LOTS', 'GSM'))->toBeTrue();

    // The master renames, and the flags that are not there.
    expect($oracle->hasColumn('QUALITIES', 'DESCRIPTION'))->toBeTrue()
        ->and($oracle->hasColumn('QUALITIES', 'QUALITY_NAME'))->toBeFalse()
        ->and($oracle->hasColumn('COST_CENTRES', 'COST_CENTRE'))->toBeTrue()
        ->and($oracle->hasColumn('COST_CENTRES', 'CC_NAME'))->toBeFalse()
        ->and($oracle->hasColumn('COST_CENTRES', 'CC_SHORT'))->toBeFalse()
        ->and($oracle->hasColumn('COST_CENTRES', 'CLOSED'))->toBeTrue()
        ->and($oracle->hasColumn('COST_CENTRES', 'IS_ACTIVE'))->toBeFalse()
        ->and($oracle->hasColumn('PROCESSING_OPERATIONS', 'IS_ACTIVE'))->toBeFalse();
});

it('refuses every write from the application, on every source model', function () {
    $row = ProcessingLot::query()->firstOrFail();

    expect(fn () => $row->update(['REMARKS' => 'edited']))->toThrow(LogicException::class);
    expect(fn () => $row->delete())->toThrow(LogicException::class);

    $models = sourceModelClasses();
    expect($models)->toHaveCount(12);

    foreach ($models as $class) {
        expect(fn () => $class::query()->create([]))
            ->toThrow(LogicException::class, 'The Oracle source is read-only');

        $existing = $class::query()->first();
        if ($existing !== null) {
            expect(fn () => $existing->delete())->toThrow(LogicException::class);
        }
    }
});

it('keeps quantities as whole metres, the way Oracle stores them', function () {
    $row = ProcessingLot::query()->firstOrFail();

    expect($row->QNTY_USED)->toBeInt()->and($row->QNTY_PRODUCED)->toBeInt()
        ->and(GreigeIssueLine::query()->firstOrFail()->QNTY)->toBeInt();
});

it('does not treat LOT_NUM as unique', function () {
    // The lot↔contract-line relation is PC_DTL_LOTS, not PROCESSING_CONTRACT_D.LOT_NUM.
    $duplicated = ProcessingContractLot::query()
        ->where('LOT_NUM', OracleMockSeeder::DUPLICATED_LOT_NUM)
        ->get();

    expect($duplicated->pluck('PC_DTL_ID')->unique())->toHaveCount(2)
        ->and($duplicated->pluck('PC_MAIN_ID')->unique())->toHaveCount(2);
});

it('does not rely on PROCESSING_CONTRACT_D.LOT_NUM, which is mostly empty', function () {
    $lines = ProcessingContractLine::query()->count();
    $withLot = ProcessingContractLine::query()->whereNotNull('LOT_NUM')->count();

    // Mirrors the live instance, where 8 212 of 45 561 lines carry it: the column exists and is the
    // exception, not the rule.
    expect($withLot)->toBeGreaterThan(0)->toBeLessThan($lines);

    // A lot whose line does NOT carry it is still found, because the reader goes through PC_DTL_LOTS.
    $orphan = ProcessingContractLine::query()->whereNull('LOT_NUM')->orderBy('PC_DTL_ID')->firstOrFail();
    $lotNum = (int) ProcessingContractLot::query()->where('PC_DTL_ID', $orphan->PC_DTL_ID)->firstOrFail()->LOT_NUM;

    $rows = $this->reader->contractLinesForLot($lotNum);

    expect($rows->pluck('PC_DTL_ID')->map(fn ($id) => (int) $id))->toContain((int) $orphan->PC_DTL_ID);
});

it('reads the movement as CC_CODE to TO_CC_CODE', function () {
    $lot = ProcessingLot::query()->orderBy('PROCESSING_LOT_ID')->firstOrFail();
    $rows = $this->reader->processingRowsForLot((int) $lot->LOT_NUM, (int) $lot->CONTRACT_NUM);

    expect($rows)->not->toBeEmpty()
        ->and($this->reader->currentCostCentre((int) $lot->LOT_NUM, (int) $lot->CONTRACT_NUM))
        ->toBe((int) $rows->last()->TO_CC_CODE);
});

it('derives metres per cost centre from arrivals minus departures', function () {
    $lot = ProcessingLot::query()->orderBy('PROCESSING_LOT_ID')->firstOrFail();
    $lotNum = (int) $lot->LOT_NUM;
    $contract = (int) $lot->CONTRACT_NUM;

    $balances = $this->reader->balancesByCostCentre($lotNum, $contract);
    $expected = [];
    foreach ($this->reader->processingRowsForLot($lotNum, $contract) as $row) {
        $expected[(int) $row->CC_CODE] = ($expected[(int) $row->CC_CODE] ?? 0) - (int) $row->QNTY_USED;
        $expected[(int) $row->TO_CC_CODE] = ($expected[(int) $row->TO_CC_CODE] ?? 0) + (int) $row->QNTY_PRODUCED;
    }
    ksort($expected);

    expect($balances)->toBe($expected);
});

it('does not multiply a processing row that shares its lot number with another contract', function () {
    // The defect in the supplied view: joining on lot number alone would return this row twice.
    $duplicated = ProcessingLot::query()->where('LOT_NUM', OracleMockSeeder::DUPLICATED_LOT_NUM)->get();
    expect($duplicated)->not->toBeEmpty();

    $from = ProcessingLot::query()->min('DATED');
    $to = ProcessingLot::query()->max('DATED');
    $movements = $this->reader->movements((string) $from, (string) $to);

    $processRows = $movements->filter(fn ($m) => $m->TRANS_TYPE !== OracleSourceReader::TRANS_GREIGE_ISSUE);

    expect($processRows)->toHaveCount(ProcessingLot::query()->count() * 2)   // one USED + one PRODUCED per row
        ->and($movements->filter(fn ($m) => (int) $m->LOT_NUM === OracleMockSeeder::DUPLICATED_LOT_NUM
            && $m->TRANS_TYPE === OracleSourceReader::TRANS_PROCESS_USED))
        ->toHaveCount($duplicated->count());
});

it('exposes PC_DTL_ID, which the supplied view leaves out', function () {
    $from = ProcessingLot::query()->min('DATED');
    $to = ProcessingLot::query()->max('DATED');

    $movements = $this->reader->movements((string) $from, (string) $to);

    expect($movements)->not->toBeEmpty()
        ->and($movements->every(fn ($m) => property_exists($m, 'PC_DTL_ID')))->toBeTrue()
        ->and($movements->firstWhere('TRANS_TYPE', OracleSourceReader::TRANS_GREIGE_ISSUE)->PC_DTL_ID)->not->toBeNull();

    // Each PROCESS-* row's key is its own column now, not something matched back through GREIGE_ISSUE_D.
    $processing = ProcessingLot::query()->orderBy('PROCESSING_LOT_ID')->firstOrFail();
    $used = $movements->first(fn ($m) => $m->TRANS_TYPE === OracleSourceReader::TRANS_PROCESS_USED
        && (int) $m->LOT_NUM === (int) $processing->LOT_NUM);

    expect((int) $used->PC_DTL_ID)->toBe((int) $processing->PC_DTL_ID);
});

it('signs the ledger: issues positive, consumption negative, production positive', function () {
    $from = ProcessingLot::query()->min('DATED');
    $to = ProcessingLot::query()->max('DATED');
    $movements = $this->reader->movements((string) $from, (string) $to);

    expect($movements->where('TRANS_TYPE', OracleSourceReader::TRANS_PROCESS_USED)->every(fn ($m) => $m->QNTY <= 0))->toBeTrue()
        ->and($movements->where('TRANS_TYPE', OracleSourceReader::TRANS_PROCESS_PRODUCED)->every(fn ($m) => $m->QNTY >= 0))->toBeTrue();
});

it('joins the contract header to its detail lines', function () {
    $line = ProcessingContractLine::query()->orderBy('PC_DTL_ID')->firstOrFail();
    $header = ProcessingContractHeader::query()->findOrFail($line->PC_MAIN_ID);
    $lotNum = (int) ProcessingContractLot::query()->where('PC_DTL_ID', $line->PC_DTL_ID)->firstOrFail()->LOT_NUM;

    $rows = $this->reader->contractLinesForLot($lotNum, (int) $header->CONTRACT_NUM);

    expect($rows)->toHaveCount(1);
    $row = $rows->first();

    // The header's columns, which PROCESSING_CONTRACT_D does not have.
    expect((int) $row->CONTRACT_NUM)->toBe((int) $header->CONTRACT_NUM)
        ->and((int) $row->PARTY_CODE)->toBe((int) $header->PARTY_CODE)
        ->and($row->REF_NUM)->toBe($header->REF_NUM)
        ->and($row->DATED)->not->toBeNull()
        // and the line's own.
        ->and((int) $row->PC_DTL_ID)->toBe((int) $line->PC_DTL_ID)
        ->and((int) $row->QNTY)->toBe((int) $line->QNTY);

    expect($this->reader->contractLine((int) $line->PC_DTL_ID))->not->toBeNull()
        ->and((int) $this->reader->contractLine((int) $line->PC_DTL_ID, $lotNum)->CONTRACT_NUM)
        ->toBe((int) $header->CONTRACT_NUM);
});

it('takes GSM from PC_DTL_LOTS without multiplying the contract line', function () {
    // A line whose lot has several PC_DTL_LOTS rows — the fan-out a join would turn into duplicate lines.
    $pcDtlId = (int) ProcessingContractLot::query()
        ->selectRaw('PC_DTL_ID, count(*) as N')
        ->groupBy('PC_DTL_ID')
        ->orderByDesc('N')
        ->firstOrFail()
        ->PC_DTL_ID;

    $lots = ProcessingContractLot::query()->where('PC_DTL_ID', $pcDtlId)->get();
    expect($lots->count())->toBeGreaterThan(1)
        ->and($lots->pluck('GSM')->unique()->count())->toBeGreaterThan(1);

    $line = ProcessingContractLine::query()->findOrFail($pcDtlId);
    $lotNum = (int) $lots->first()->LOT_NUM;
    $rows = $this->reader->contractLinesForLot($lotNum);
    $matching = $rows->filter(fn ($r) => (int) $r->PC_DTL_ID === $pcDtlId);

    expect($matching)->toHaveCount(1)
        ->and((int) $matching->first()->GSM)->toBe((int) $lots->where('LOT_NUM', $lotNum)->min('GSM'))
        ->and((int) $matching->first()->QNTY)->toBe((int) $line->QNTY);

    // Without a lot number the GSM is the lowest across the whole line, which is a different question.
    expect((int) $this->reader->contractLine($pcDtlId)->GSM)->toBe((int) $lots->min('GSM'));
});

it('resolves every code master the transaction tables need', function () {
    $masters = $this->reader->masters();

    expect(array_keys($masters))->toBe(['cost_centres', 'machines', 'operations', 'designs', 'colours', 'parties', 'qualities']);

    foreach ($masters as $name => $map) {
        expect($map)->not->toBeEmpty("{$name} is populated");
    }

    // A quality can have no DESCRIPTION — 672 of 3 035 live rows do not. The code is kept, the name is null.
    expect($masters['qualities'])->toHaveKey(4106)
        ->and($masters['qualities'][4106])->toBeNull();

    // Machines are cost centres: one table, two foreign-key roles.
    expect($masters['machines'])->toBe($masters['cost_centres']);

    $row = ProcessingLot::query()->whereNotNull('OPERATION_CODE')->firstOrFail();
    expect($masters['cost_centres'][(int) $row->CC_CODE])->not->toBeEmpty()
        ->and($masters['machines'][(int) $row->MACHINE_CODE])->not->toBeEmpty()
        ->and($masters['operations'][$this->reader->operationKey((int) $row->CC_CODE, (int) $row->OPERATION_CODE)])
        ->not->toBeEmpty();
});

it('keys the operation master per cost centre, because the code alone is not unique', function () {
    $reused = OracleMockSeeder::REUSED_OPERATIONS[0];
    [$costCentre, $code, $name] = $reused;

    $rows = OracleOperation::query()->where('OPERATION_CODE', $code)->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('OPERATION_NAME')->unique())->toHaveCount(2);

    $operations = $this->reader->masters()['operations'];

    // Both names survive, because the key carries the cost centre.
    expect($operations[$this->reader->operationKey($costCentre, $code)])->toBe($name)
        ->and($operations[$this->reader->operationKey(OracleMockSeeder::OPERATIONS[$code][1], $code)])
        ->toBe(OracleMockSeeder::OPERATIONS[$code][0]);
});

it('reads CLOSED as an inverted flag, not an IS_ACTIVE', function () {
    // NULL and 'N' both mean open; only an explicit 'Y' is closed (verified against the live instance,
    // where 50 rows are NULL and 40 are 'N').
    $open = CostCentre::query()->open()->count();

    expect($open)->toBe(CostCentre::query()->count())
        ->and(CostCentre::query()->whereNull('CLOSED')->exists())->toBeTrue()
        ->and(CostCentre::query()->firstOrFail()->isClosed())->toBeFalse();
});

it('allows a null OPERATION_CODE, as Oracle does', function () {
    expect(ProcessingLot::query()->whereNull('OPERATION_CODE')->exists())->toBeTrue();
});

it('keeps the source out of the application schema', function () {
    // The app's own tables are untouched by the mock seeder.
    expect(Lot::query()->count())->toBe(0)
        ->and(LotMovement::query()->count())->toBe(0);
});

/*
 * The mock schema migration must never run DDL against the mill's ERP: Oracle is a *source* and this
 * application only reads from it. That is a guard in the migration, so it is asserted here rather than left
 * to the docblock — up() and down() both return before they touch Schema unless `oracle_src` is a local
 * stand-in and the environment is not production.
 *
 * Calling up()/down() here cannot reach Oracle even if the guard were broken: the `oracle_src` connection
 * is already resolved to the in-memory SQLite one and changing the config does not re-resolve it.
 */
it('creates and drops nothing when the source is a real Oracle instance', function () {
    $oracle = Schema::connection(OracleSourceConnection::NAME);
    $before = collect($oracle->getTables())->pluck('name')->sort()->values()->all();
    expect($before)->toContain('PROCESSING_LOTS');

    $migration = require database_path('migrations/2026_10_31_000100_align_mock_oracle_source_tables.php');

    config(['database.connections.'.OracleSourceConnection::NAME.'.driver' => 'oracle']);
    expect(OracleSourceConnection::isMocked())->toBeFalse();
    $migration->up();
    $migration->down();

    // And nothing on a production box either, whatever the driver is.
    config(['database.connections.'.OracleSourceConnection::NAME.'.driver' => 'sqlite', 'app.env' => 'production']);
    $migration->up();
    $migration->down();

    config(['app.env' => 'testing']);

    expect(collect($oracle->getTables())->pluck('name')->sort()->values()->all())->toBe($before)
        ->and(ProcessingLot::query()->exists())->toBeTrue();
});

it('refuses to seed the mock when the connection is not a stand-in', function () {
    config(['database.connections.'.OracleSourceConnection::NAME.'.driver' => 'oracle']);

    expect(OracleSourceConnection::isMocked())->toBeFalse();

    foreach (sourceModelClasses() as $class) {
        expect(fn () => $class::seedMock([[]]))->toThrow(RuntimeException::class);
    }
});
