<?php

namespace App\Modules\Foundation\Support;

final class QualityParser
{
    private const CONSTRUCTION = '/^(\d+)X(\d+)\/(\S+?)X(\S+)\s+(.*)$/u';

    private const LOOM = '/^(.*?)\s*\[(\w+)\]\s*(.*)$/u';

    public static function parse(string $description): ParsedQuality
    {
        $text = trim($description);

        if (! preg_match(self::CONSTRUCTION, $text, $m)) {
            return new ParsedQuality(null, null, null, null, null, null, $text === '' ? null : $text);
        }

        [, $ends, $picks, $warp, $weft, $rest] = $m;

        // Two passes: an optional [LOOM] group in one regex lets the lazy composition swallow everything.
        if (preg_match(self::LOOM, $rest, $r)) {
            [, $composition, $loom, $notes] = $r;
        } else {
            [$composition, $loom, $notes] = [$rest, '', ''];
        }

        $unknown = (int) $ends === 0 && (int) $picks === 0;

        return new ParsedQuality(
            ends: $unknown ? null : (int) $ends,
            picks: $unknown ? null : (int) $picks,
            warpCount: $unknown ? null : $warp,
            weftCount: $unknown ? null : $weft,
            composition: trim($composition) === '' ? null : trim($composition),
            loom: $loom === '' ? null : $loom,
            notes: trim($notes) === '' ? null : trim($notes),
        );
    }
}
