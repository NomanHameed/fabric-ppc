<?php

use App\Modules\Production\Services\BatchSplitter;

it('splits in proportion and lands the rounding remainder on the largest line', function () {
    expect(BatchSplitter::split(1000, ['a' => 300, 'b' => 600, 'c' => 100]))->toBe(['a' => 300.0, 'b' => 600.0, 'c' => 100.0]);
    $r = BatchSplitter::split(100, ['a' => 1, 'b' => 1, 'c' => 1]);
    expect(array_sum($r))->toBe(100.0)->and($r['a'])->toBe(33.34)->and($r['b'])->toBe(33.33)->and($r['c'])->toBe(33.33);
});

it('splits equally when nothing is available and returns nothing for no lines', function () {
    expect(BatchSplitter::split(10, ['a' => 0, 'b' => 0]))->toBe(['a' => 5.0, 'b' => 5.0])
        ->and(BatchSplitter::split(10, []))->toBe([]);
});

it('keeps every share at two decimals and the sum exact for awkward totals', function () {
    foreach ([[999.99, [1 => 333, 2 => 333, 3 => 334]], [0.01, [1 => 500, 2 => 500]], [1234.56, ['x' => 100.5, 'y' => 0, 'z' => 899.25]]] as [$total, $available]) {
        $r = BatchSplitter::split($total, $available);
        expect(array_keys($r))->toBe(array_keys($available))
            ->and(round(array_sum($r), 2))->toBe($total);
        foreach ($r as $share) {
            expect($share)->toBe(round($share, 2))->and($share)->toBeGreaterThanOrEqual(0.0);
        }
    }
    // the remainder goes to the first of the largest lines, and a line with nothing available gets nothing
    $r = BatchSplitter::split(1234.56, ['x' => 100.5, 'y' => 0, 'z' => 899.25]);
    expect($r['y'])->toBe(0.0)->and($r['z'])->toBeGreaterThan($r['x']);
});

it('refuses an infinite or NaN total or availability', function () {
    foreach ([[INF, ['a' => 1]], [NAN, ['a' => 1]], [10, ['a' => INF, 'b' => 1]], [10, ['a' => 1, 'b' => NAN]]] as [$total, $available]) {
        expect(fn () => BatchSplitter::split($total, $available))->toThrow(InvalidArgumentException::class);
    }
});

it('treats negative availability as zero', function () {
    expect(BatchSplitter::split(30, ['a' => -5, 'b' => 10, 'c' => 20]))->toBe(['a' => 0.0, 'b' => 10.0, 'c' => 20.0]);
});
