<?php

use App\Models\User;
use App\Modules\Foundation\Models\Party;
use App\Modules\OracleSync\Services\ImportCommitService;
use App\Modules\OracleSync\Services\ImportPreviewService;
use App\Modules\OracleSync\Sources\ExcelUploadSource;
use App\Modules\Production\Models\Lot;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

const ORACLE_HEADERS = [
    'PARTY', 'PARTY_CODE', 'CONTRACT_NUM', 'REF_NUM', 'DATED', 'QUALITY', 'Q_CODE', 'LOT_NUM1', 'GSM', 'DESIGN_CODE',
    'DESIGN', 'COLOUR_NAME', 'COLOUR_CODE', 'G_WIDTH', 'F_WIDTH', 'PROCESS_TYPE', 'PC_DTL_ID', 'QNTY', 'QNTY_CONT',
];

/** @param list<array> $dataRows each row values in ORACLE_HEADERS order */
function buildOracleXlsx(array $dataRows): string
{
    $path = tempnam(sys_get_temp_dir(), 'orc').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(ORACLE_HEADERS));
    foreach ($dataRows as $row) {
        $writer->addRow(Row::fromValues($row));
    }
    $writer->close();

    return $path;
}

/** A minimal, valid data row for the 19 required headers, keyed by the fields callers usually vary. */
function oracleRow(array $overrides = []): array
{
    $defaults = [
        'PARTY' => 'NORMAL PARTY', 'PARTY_CODE' => 1, 'CONTRACT_NUM' => 700001, 'REF_NUM' => 'R', 'DATED' => '01-SEP-26',
        'QUALITY' => 'Q', 'Q_CODE' => 10, 'LOT_NUM1' => 700001, 'GSM' => '', 'DESIGN_CODE' => '', 'DESIGN' => '',
        'COLOUR_NAME' => '', 'COLOUR_CODE' => '', 'G_WIDTH' => 60, 'F_WIDTH' => 55, 'PROCESS_TYPE' => 'DYED',
        'PC_DTL_ID' => 9000, 'QNTY' => 100, 'QNTY_CONT' => 100,
    ];
    $row = array_merge($defaults, $overrides);

    return array_map(fn ($h) => $row[$h], ORACLE_HEADERS);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->preview = app(ImportPreviewService::class);
    $this->commit = app(ImportCommitService::class);
});

it('keeps a duplicate-line error alive when resolving an unknown party for its code', function () {
    Party::create(['name' => 'NORMAL PARTY', 'type' => 'brand', 'oracle_code' => 1]);

    $path = buildOracleXlsx([
        oracleRow(['PARTY' => 'UNKNOWN CO', 'PARTY_CODE' => 999, 'CONTRACT_NUM' => 500001, 'LOT_NUM1' => 500001, 'PC_DTL_ID' => 9001]),
        oracleRow(['PARTY' => 'UNKNOWN CO', 'PARTY_CODE' => 999, 'CONTRACT_NUM' => 500002, 'LOT_NUM1' => 500002, 'PC_DTL_ID' => 9001]),
        oracleRow(['PARTY' => 'NORMAL PARTY', 'PARTY_CODE' => 1, 'CONTRACT_NUM' => 500003, 'LOT_NUM1' => 500003, 'PC_DTL_ID' => 9002]),
    ]);

    $import = $this->preview->preview(new ExcelUploadSource($path), $this->user);
    expect($import->error_count)->toBe(2);

    $import = $this->preview->resolveParty($import, 999, ['mode' => 'create', 'type' => 'brand']);

    expect($import->error_count)->toBe(1);
    $errorRow = $import->rows()->where('action', 'error')->first();
    expect($errorRow->message)->toStartWith('duplicate_line')
        ->and($errorRow->oracle_line_id)->toBe(9001);
});

it('leaves a second Oracle code unresolved when it name-matches a party already claimed this run', function () {
    $party = Party::create(['name' => 'ABC', 'type' => 'brand']);
    $party->aliases()->create(['alias' => 'ABC LTD']);

    $path = buildOracleXlsx([
        oracleRow(['PARTY' => 'ABC', 'PARTY_CODE' => 100, 'CONTRACT_NUM' => 600001, 'LOT_NUM1' => 600001, 'PC_DTL_ID' => 9101]),
        oracleRow(['PARTY' => 'ABC LTD', 'PARTY_CODE' => 200, 'CONTRACT_NUM' => 600002, 'LOT_NUM1' => 600002, 'PC_DTL_ID' => 9102]),
    ]);

    $import = $this->preview->preview(new ExcelUploadSource($path), $this->user);

    expect($import->error_count)->toBeGreaterThan(0)
        ->and(collect($import->unresolved_parties)->pluck('code')->all())->toBe([200])
        ->and(collect($import->unresolved_parties)->firstWhere('code', 100))->toBeNull();
});

it('refuses to resolve a party on an import that is not previewed', function () {
    $path = buildOracleXlsx([oracleRow()]);
    $import = $this->preview->preview(new ExcelUploadSource($path), $this->user);
    $this->preview->discard($import);

    $this->preview->resolveParty($import, 1, ['mode' => 'create', 'type' => 'brand']);
})->throws(DomainException::class);

it('refuses to resolve a code that has no unknown-party rows', function () {
    $path = buildOracleXlsx([oracleRow()]);
    $import = $this->preview->preview(new ExcelUploadSource($path), $this->user);

    $this->preview->resolveParty($import, 424242, ['mode' => 'create', 'type' => 'brand']);
})->throws(ValidationException::class);

it('refuses to link the second of two codes sharing one uncoded party, leaving it unlinked', function () {
    $party = Party::create(['name' => 'ABC', 'type' => 'brand']);
    $party->aliases()->create(['alias' => 'ABC LTD']);

    $path = buildOracleXlsx([
        oracleRow(['PARTY' => 'ABC', 'PARTY_CODE' => 100, 'CONTRACT_NUM' => 610001, 'LOT_NUM1' => 610001, 'PC_DTL_ID' => 9201]),
        oracleRow(['PARTY' => 'ABC LTD', 'PARTY_CODE' => 200, 'CONTRACT_NUM' => 610002, 'LOT_NUM1' => 610002, 'PC_DTL_ID' => 9202]),
    ]);
    $import = $this->preview->preview(new ExcelUploadSource($path), $this->user);

    try {
        $this->preview->resolveParty($import, 200, ['mode' => 'link', 'party_id' => $party->id]);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('party_id');
    }

    expect($party->fresh()->oracle_code)->toBeNull();
});

it('commits both codes when the conflicting one creates a new party instead of linking', function () {
    $party = Party::create(['name' => 'ABC', 'type' => 'brand']);
    $party->aliases()->create(['alias' => 'ABC LTD']);

    $path = buildOracleXlsx([
        oracleRow(['PARTY' => 'ABC', 'PARTY_CODE' => 100, 'CONTRACT_NUM' => 620001, 'LOT_NUM1' => 620001, 'PC_DTL_ID' => 9301]),
        oracleRow(['PARTY' => 'ABC LTD', 'PARTY_CODE' => 200, 'CONTRACT_NUM' => 620002, 'LOT_NUM1' => 620002, 'PC_DTL_ID' => 9302]),
    ]);
    $import = $this->preview->preview(new ExcelUploadSource($path), $this->user);

    $import = $this->preview->resolveParty($import, 200, ['mode' => 'create', 'type' => 'brand']);
    expect($import->error_count)->toBe(0);

    $this->commit->commit($import, $this->user);

    $newParty = Party::where('oracle_code', 200)->first();
    $lot100 = Lot::where('oracle_line_id', 9301)->first();
    $lot200 = Lot::where('oracle_line_id', 9302)->first();

    expect($party->fresh()->oracle_code)->toBe(100)
        ->and($newParty)->not->toBeNull()
        ->and($newParty->id)->not->toBe($party->id)
        ->and($lot100->contract->party_id)->toBe($party->id)
        ->and($lot200->contract->party_id)->toBe($newParty->id);
});
