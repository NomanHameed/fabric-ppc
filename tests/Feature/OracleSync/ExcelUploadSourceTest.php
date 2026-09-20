<?php

use App\Modules\OracleSync\Data\OracleLotRow;
use App\Modules\OracleSync\Data\OracleRowError;
use App\Modules\OracleSync\Exceptions\InvalidOracleFile;
use App\Modules\OracleSync\Sources\ExcelUploadSource;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

function fixtureFile(): string
{
    return base_path('tests/Fixtures/oracle-wip-2026-09.xlsx');
}

function makeXlsx(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'orc').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    foreach ($rows as $r) {
        $writer->addRow(Row::fromValues($r));
    }
    $writer->close();

    return $path;
}

it('reads all 623 rows of the real export', function () {
    $rows = iterator_to_array((new ExcelUploadSource(fixtureFile()))->rows());
    expect($rows)->toHaveCount(623);
});

it('maps the first row exactly', function () {
    $rows = (new ExcelUploadSource(fixtureFile()))->rows();
    $first = null;
    foreach ($rows as $rowNo => $row) {
        $first = [$rowNo, $row];
        break;
    }
    [$rowNo, $row] = $first;
    expect($rowNo)->toBe(2)
        ->and($row->toArray())->toBe([
            'party_name' => 'AL HAMD TEXTILE   (PRIVATE) LTD', 'party_code' => 561, 'po_no' => 261061, 'ref_no' => 'ALP-3104',
            'po_date' => '2026-08-20', 'quality_description' => '0X0/0X0 100% CTN [AJ]  JAQ -AHT-3057', 'quality_code' => 2931,
            'lot_no' => '265025', 'oracle_line_id' => 47090, 'gsm' => 79, 'design_code' => '17020', 'design' => 'D1',
            'colour_code' => '46', 'colour' => 'BLACK', 'g_width' => 50.0, 'f_width' => 40.0, 'po_type' => 'DYED',
            'greige_qty' => 3650.0, 'contract_qty' => 3650.0,
        ]);
});

it('keeps both rows of a lot number reused across POs', function () {
    $lines = collect(iterator_to_array((new ExcelUploadSource(fixtureFile()))->rows()))
        ->filter(fn ($r) => $r->lotNo === '264899')->map(fn ($r) => [$r->poNo, $r->oracleLineId])->values()->all();
    expect($lines)->toBe([[261071, 47119], [261072, 47120]]);
});

it('rejects a file with missing headers', function () {
    $path = makeXlsx([['PARTY', 'CONTRACT_NUM'], ['X', 1]]);
    iterator_to_array((new ExcelUploadSource($path))->rows());
})->throws(InvalidOracleFile::class, 'PARTY_CODE');

it('rejects a corrupt xlsx that is not a valid zip', function () {
    $path = tempnam(sys_get_temp_dir(), 'orc').'.xlsx';
    file_put_contents($path, 'not a zip file at all');
    iterator_to_array((new ExcelUploadSource($path))->rows());
})->throws(InvalidOracleFile::class);

it('yields an error row, with its row number, for an unparseable value instead of aborting (M-6)', function () {
    $headers = ['PARTY', 'PARTY_CODE', 'CONTRACT_NUM', 'REF_NUM', 'DATED', 'QUALITY', 'Q_CODE', 'LOT_NUM1', 'GSM', 'DESIGN_CODE',
        'DESIGN', 'COLOUR_NAME', 'COLOUR_CODE', 'G_WIDTH', 'F_WIDTH', 'PROCESS_TYPE', 'PC_DTL_ID', 'QNTY', 'QNTY_CONT'];
    $good = ['P', 1, 260001, 'R', '01-SEP-26', 'Q', 1, 260001, '', 1, 'D', 'C', 1, 60, '55', 'DYED', 1, 100, 100];
    $bad = $good;
    $bad[4] = '31-XYZ-26';
    $bad[16] = 2;
    $badQty = $good;
    $badQty[17] = 'abc';
    $badQty[16] = 'x';
    $path = makeXlsx([$headers, $good, $bad, $badQty]);
    $rows = iterator_to_array((new ExcelUploadSource($path))->rows());
    expect($rows[2])->toBeInstanceOf(OracleLotRow::class)
        ->and($rows[3])->toBeInstanceOf(OracleRowError::class)
        ->and($rows[3]->rowNo)->toBe(3)
        ->and($rows[3]->oracleLineId)->toBe(2)
        ->and($rows[3]->message)->not->toBe('')
        ->and($rows[4])->toBeInstanceOf(OracleRowError::class)
        ->and($rows[4]->oracleLineId)->toBeNull()
        ->and($rows[4]->message)->toContain('PC_DTL_ID');
});
