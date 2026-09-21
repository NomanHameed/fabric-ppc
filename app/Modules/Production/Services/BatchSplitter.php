<?php

namespace App\Modules\Production\Services;

/**
 * "Split batch total" (spec §6.3.4): spread a batch total over its lines in proportion to the metres available to
 * each line at its source position. Every share is rounded to 2 dp and the rounding remainder lands on the line
 * with the most metres available (the first such line on a tie), so the shares always sum to exactly the total.
 * The result is a proposal: the screen lets the user edit every figure afterwards.
 */
final class BatchSplitter
{
    /**
     * @param  array<int|string, float>  $available  line key => metres available at that line's source position
     * @return array<int|string, float> line key => allocated metres (2 dp), summing exactly to $total; [] for no lines
     */
    public static function split(float $total, array $available): array
    {
        if ($available === []) {
            return [];
        }
        $available = array_map(fn ($qty): float => max(0.0, (float) $qty), $available);
        $sum = array_sum($available);
        $keys = array_keys($available);
        $out = [];
        $allocated = '0.00';
        foreach ($keys as $k) {
            // equal shares when nothing is available anywhere: the user is entering a batch the ledger does not know about yet
            $share = $sum > 0 ? $total * $available[$k] / $sum : $total / count($keys);
            $out[$k] = round($share, 2);
            $allocated = bcadd($allocated, number_format($out[$k], 2, '.', ''), 2);
        }
        $largest = $sum > 0 ? array_search(max($available), $available, true) : $keys[0];
        $remainder = bcsub(number_format(round($total, 2), 2, '.', ''), $allocated, 2);
        $out[$largest] = (float) bcadd(number_format($out[$largest], 2, '.', ''), $remainder, 2);

        return $out;
    }
}
