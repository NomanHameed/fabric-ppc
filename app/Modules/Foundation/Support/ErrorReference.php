<?php

namespace App\Modules\Foundation\Support;

use Throwable;
use WeakMap;

/** One short reference per exception instance, shared by the log line and the page the user sees (spec §9). */
final class ErrorReference
{
    /** @var WeakMap<Throwable, string>|null */
    private static ?WeakMap $refs = null;

    public static function for(Throwable $e): string
    {
        self::$refs ??= new WeakMap;

        return self::$refs[$e] ??= strtoupper(substr(bin2hex(random_bytes(5)), 0, 10));
    }
}
