<?php

use App\Modules\OracleSync\Source\Models\CostCentre;
use App\Modules\OracleSync\Source\Models\GreigeIssueLine;
use App\Modules\OracleSync\Source\Models\ProcessingContractLine;
use App\Modules\OracleSync\Source\Models\ProcessingLot;
use App\Modules\OracleSync\Source\OracleSourceConnection;
use App\Modules\OracleSync\Source\OracleSourceReader;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\LotMovement;
use Database\Seeders\OracleMockSeeder;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->seed(OracleMockSeeder::class);
    $this->reader = app(OracleSourceReader::class);
});

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
        'PROCESSING_LOTS', 'GREIGE_ISSUE_M', 'GREIGE_ISSUE_D', 'PROCESSING_CONTRACT_D',
        'COST_CENTRE_M', 'MACHINE_M', 'OPERATION_M', 'DESIGN_M', 'COLOUR_M', 'PARTY_M', 'QUALITY_M',
    ] as $table) {
        expect($oracle->hasTable($table))->toBeTrue("{$table} exists on the source connection");
    }

    expect(CostCentre::query()->getModel()->getConnectionName())->toBe(OracleSourceConnection::NAME);
});

it('refuses every write from the application', function () {
    $row = ProcessingLot::query()->firstOrFail();

    expect(fn () => $row->update(['REMARKS' => 'edited']))->toThrow(LogicException::class);
    expect(fn () => $row->delete())->toThrow(LogicException::class);
    expect(fn () => ProcessingLot::query()->create(['PROCESSING_LOT_ID' => 1]))->toThrow(LogicException::class);
});

it('keeps quantities as whole metres, the way Oracle stores them', function () {
    $row = ProcessingLot::query()->firstOrFail();

    expect($row->QNTY_USED)->toBeInt()->and($row->QNTY_PRODUCED)->toBeInt()
        ->and(GreigeIssueLine::query()->firstOrFail()->QNTY)->toBeInt();
});

it('does not treat LOT_NUM as unique', function () {
    $duplicated = ProcessingContractLine::query()->where('LOT_NUM', 640900)->get();

    expect($duplicated)->toHaveCount(2)
        ->and($duplicated->pluck('CONTRACT_NUM')->unique())->toHaveCount(2)
        ->and($duplicated->pluck('PC_DTL_ID')->unique())->toHaveCount(2);
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
    $duplicated = ProcessingLot::query()->where('LOT_NUM', 640900)->get();
    expect($duplicated)->not->toBeEmpty();

    $from = ProcessingLot::query()->min('DATED');
    $to = ProcessingLot::query()->max('DATED');
    $movements = $this->reader->movements((string) $from, (string) $to);

    $processRows = $movements->filter(fn ($m) => $m->TRANS_TYPE !== OracleSourceReader::TRANS_GREIGE_ISSUE);

    expect($processRows)->toHaveCount(ProcessingLot::query()->count() * 2)   // one USED + one PRODUCED per row
        ->and($movements->filter(fn ($m) => (int) $m->LOT_NUM === 640900 && $m->TRANS_TYPE === OracleSourceReader::TRANS_PROCESS_USED))
        ->toHaveCount($duplicated->count());
});

it('exposes PC_DTL_ID, which the supplied view leaves out', function () {
    $from = ProcessingLot::query()->min('DATED');
    $to = ProcessingLot::query()->max('DATED');

    $movements = $this->reader->movements((string) $from, (string) $to);

    expect($movements)->not->toBeEmpty()
        ->and($movements->every(fn ($m) => property_exists($m, 'PC_DTL_ID')))->toBeTrue()
        ->and($movements->firstWhere('TRANS_TYPE', OracleSourceReader::TRANS_GREIGE_ISSUE)->PC_DTL_ID)->not->toBeNull();
});

it('signs the ledger: issues positive, consumption negative, production positive', function () {
    $from = ProcessingLot::query()->min('DATED');
    $to = ProcessingLot::query()->max('DATED');
    $movements = $this->reader->movements((string) $from, (string) $to);

    expect($movements->where('TRANS_TYPE', OracleSourceReader::TRANS_PROCESS_USED)->every(fn ($m) => $m->QNTY <= 0))->toBeTrue()
        ->and($movements->where('TRANS_TYPE', OracleSourceReader::TRANS_PROCESS_PRODUCED)->every(fn ($m) => $m->QNTY >= 0))->toBeTrue();
});

it('resolves every code master the transaction tables need', function () {
    $masters = $this->reader->masters();

    expect(array_keys($masters))->toBe(['cost_centres', 'machines', 'operations', 'designs', 'colours', 'parties', 'qualities']);

    foreach ($masters as $name => $map) {
        expect($map)->not->toBeEmpty("{$name} is populated");
    }

    $row = ProcessingLot::query()->whereNotNull('OPERATION_CODE')->firstOrFail();
    expect($masters['cost_centres'][(int) $row->CC_CODE])->not->toBeEmpty()
        ->and($masters['machines'][(int) $row->MACHINE_CODE])->not->toBeEmpty()
        ->and($masters['operations'][(int) $row->OPERATION_CODE])->not->toBeEmpty();
});

it('allows a null OPERATION_CODE, as Oracle does', function () {
    expect(ProcessingLot::query()->whereNull('OPERATION_CODE')->exists())->toBeTrue();
});

it('keeps the source out of the application schema', function () {
    // The app's own tables are untouched by the mock seeder.
    expect(Lot::query()->count())->toBe(0)
        ->and(LotMovement::query()->count())->toBe(0);
});

it('refuses to seed the mock when the connection is not a stand-in', function () {
    config(['database.connections.'.OracleSourceConnection::NAME.'.driver' => 'oracle']);

    expect(OracleSourceConnection::isMocked())->toBeFalse();
    expect(fn () => CostCentre::seedMock([['CC_CODE' => 1, 'CC_NAME' => 'X']]))->toThrow(RuntimeException::class);
});
