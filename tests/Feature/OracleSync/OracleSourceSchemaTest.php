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
use App\Modules\OracleSync\Source\SourceModel;

/**
 * Two-schema qualification.
 *
 * The mill's source data spans two Oracle schemas — transactions in PRODUCTION, code masters in ERP — so
 * each model names its own owner and getTable() qualifies only when the driver really is `oracle`. On the
 * stand-in the same models must read bare table names, because `ERP.PARTIES` means "database ERP, table
 * PARTIES" to MySQL and nothing at all to SQLite (which is what phpunit.xml pins the suite to).
 *
 * Nothing here opens a connection: getTable() reads the configured driver, not a live handle, which is why
 * the `oracle` side can be asserted on a machine with no Oracle and no oci8 — the same approach
 * OracleSourceConfigTest takes to the connection array.
 *
 * @param  class-string<SourceModel>  $model
 */
function qualifiedTableUnderOracle(string $model): string
{
    $previous = OracleSourceConnection::driver();
    config(['database.connections.'.OracleSourceConnection::NAME.'.driver' => 'oracle']);

    try {
        return (new $model)->getTable();
    } finally {
        config(['database.connections.'.OracleSourceConnection::NAME.'.driver' => $previous]);
    }
}

/** @return array<string, array{0: class-string<SourceModel>, 1: string, 2: string}> */
function sourceModelSchemas(): array
{
    return [
        'processing lots' => [ProcessingLot::class, SourceModel::SCHEMA_PRODUCTION, 'PROCESSING_LOTS'],
        'greige issue header' => [GreigeIssueHeader::class, SourceModel::SCHEMA_PRODUCTION, 'GREIGE_ISSUE_M'],
        'greige issue line' => [GreigeIssueLine::class, SourceModel::SCHEMA_PRODUCTION, 'GREIGE_ISSUE_D'],
        'contract header' => [ProcessingContractHeader::class, SourceModel::SCHEMA_PRODUCTION, 'PROCESSING_CONTRACT_M'],
        'contract line' => [ProcessingContractLine::class, SourceModel::SCHEMA_PRODUCTION, 'PROCESSING_CONTRACT_D'],
        'contract lot' => [ProcessingContractLot::class, SourceModel::SCHEMA_PRODUCTION, 'PC_DTL_LOTS'],
        'party' => [OracleParty::class, SourceModel::SCHEMA_ERP, 'PARTIES'],
        'quality' => [OracleQuality::class, SourceModel::SCHEMA_ERP, 'QUALITIES'],
        'design' => [OracleDesign::class, SourceModel::SCHEMA_ERP, 'DESIGNS'],
        'colour' => [OracleColour::class, SourceModel::SCHEMA_ERP, 'COLOURS'],
        'operation' => [OracleOperation::class, SourceModel::SCHEMA_ERP, 'PROCESSING_OPERATIONS'],
        'cost centre' => [CostCentre::class, SourceModel::SCHEMA_ERP, 'COST_CENTRES'],
    ];
}

it('reads the bare table name on the stand-in', function (string $model, string $schema, string $table) {
    expect(OracleSourceConnection::isMocked())->toBeTrue()
        ->and((new $model)->getTable())->toBe($table)
        ->and((new $model)->unqualifiedTable())->toBe($table)
        ->and((new $model)->oracleSchema())->toBe($schema);
})->with(sourceModelSchemas());

it('qualifies the table with its owner under the oracle driver', function (string $model, string $schema, string $table) {
    expect(qualifiedTableUnderOracle($model))->toBe($schema.'.'.$table)
        // The owner is a presentational prefix only: the unqualified name never changes, which is what the
        // mock schema and the mock seeder address.
        ->and((new $model)->unqualifiedTable())->toBe($table);
})->with(sourceModelSchemas());

it('spans two schemas, which is why no single prefix would do', function () {
    $schemas = [];

    foreach (sourceModelSchemas() as [$model]) {
        $schemas[(new $model)->oracleSchema()] = true;
    }

    expect(array_keys($schemas))->toHaveCount(2)
        ->and(array_keys($schemas))->toContain(SourceModel::SCHEMA_PRODUCTION, SourceModel::SCHEMA_ERP);
});

it('tells a real Oracle connection apart from an unconfigured one', function () {
    // isOracle() is deliberately not `! isMocked()`: a missing or misspelled driver is neither, and a
    // table must not be silently qualified for it.
    config(['database.connections.'.OracleSourceConnection::NAME.'.driver' => 'oracle']);
    expect(OracleSourceConnection::isOracle())->toBeTrue()
        ->and(OracleSourceConnection::isMocked())->toBeFalse();

    config(['database.connections.'.OracleSourceConnection::NAME.'.driver' => 'pgsql']);
    expect(OracleSourceConnection::isOracle())->toBeFalse()
        ->and(OracleSourceConnection::isMocked())->toBeFalse()
        ->and((new OracleParty)->getTable())->toBe('PARTIES');

    config(['database.connections.'.OracleSourceConnection::NAME.'.driver' => 'sqlite']);
    expect(OracleSourceConnection::isOracle())->toBeFalse()
        ->and(OracleSourceConnection::isMocked())->toBeTrue();
});

it('names a table in exactly one place', function () {
    // The TABLE constant is what the migration, the seeder and the reader all address, so a rename cannot
    // leave a literal behind.
    foreach (sourceModelSchemas() as [$model, , $table]) {
        expect(constant($model.'::TABLE'))->toBe($table);
    }
});
