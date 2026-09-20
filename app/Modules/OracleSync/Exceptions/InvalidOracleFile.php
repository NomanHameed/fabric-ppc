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

    public static function unreadable(?\Throwable $previous = null): self
    {
        return new self(__('oracle.errors.unreadable_file'), 0, $previous);
    }
}
