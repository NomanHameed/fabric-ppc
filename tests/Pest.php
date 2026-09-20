<?php

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
// Concurrency: two real connections need committed rows, so no wrapping transaction (MySQL only; skipped on SQLite)
pest()->extend(TestCase::class)->use(DatabaseTruncation::class)->in('Concurrency');

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
