<?php

namespace App\Modules\OracleSync\Data;

/** A source row that could not be parsed; the preview records it as an error row instead of aborting. */
final readonly class OracleRowError
{
    public function __construct(public int $rowNo, public ?int $oracleLineId, public string $message) {}
}
