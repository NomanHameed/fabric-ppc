<?php

namespace App\Modules\Foundation\Support;

final class PartyName
{
    public static function normalize(string $name): string
    {
        $s = mb_strtoupper(trim($name));
        $s = preg_replace('/\s+/u', ' ', $s);
        $s = preg_replace("/\s*([.&'\-])\s*/u", '$1', $s);

        return trim($s);
    }
}
