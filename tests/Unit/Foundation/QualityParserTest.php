<?php

use App\Modules\Foundation\Support\QualityParser;

it('parses a standard construction', function () {
    $q = QualityParser::parse('88X60/30X30 100% VISCOSE [AJ]  (TWILL )');
    expect([$q->ends, $q->picks, $q->warpCount, $q->weftCount, $q->composition, $q->loom, $q->notes])
        ->toBe([88, 60, '30', '30', '100% VISCOSE', 'AJ', '(TWILL )']);
});

it('parses blends and trailing notes', function () {
    $q = QualityParser::parse('90X80/60X80 60:40 CTN/VIS [AJ]  +40 SLUB(AHT-2603)');
    expect([$q->ends, $q->picks, $q->warpCount, $q->weftCount, $q->composition, $q->loom, $q->notes])
        ->toBe([90, 80, '60', '80', '60:40 CTN/VIS', 'AJ', '+40 SLUB(AHT-2603)']);
});

it('treats 0X0/0X0 as unknown construction', function () {
    $q = QualityParser::parse('0X0/0X0 100% CTN [AJ]  JAQ -AHT-3057');
    expect([$q->ends, $q->picks, $q->warpCount, $q->weftCount, $q->composition, $q->notes])
        ->toBe([null, null, null, null, '100% CTN', 'JAQ -AHT-3057']);
});

it('handles missing loom and notes', function () {
    $q = QualityParser::parse('96X64/40X40 100% CTN [SL]');
    expect([$q->composition, $q->loom, $q->notes])->toBe(['100% CTN', 'SL', null]);
});

it('keeps unparseable text as notes', function () {
    $q = QualityParser::parse('SPECIAL FABRIC');
    expect([$q->ends, $q->composition, $q->notes])->toBe([null, null, 'SPECIAL FABRIC']);
});
