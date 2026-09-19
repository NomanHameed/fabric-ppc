<?php

namespace App\Modules\OracleSync\Exceptions;

use RuntimeException;

final class InvalidOracleFile extends RuntimeException
{
    /** @param list<string> $headers */
    public static function missingHeaders(array $headers): self
    {
        return new self(__('oracle.errors.missing_headers', ['headers' => implode(', ', $headers)]).' ['.implode(', ', $headers).']');
    }

    public static function badRow(int $row, string $reason): self
    {
        return new self("Row {$row}: {$reason}");
    }
}
