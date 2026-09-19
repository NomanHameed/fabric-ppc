<?php

use App\Modules\Foundation\Support\PartyName;

it('normalises party names', function (string $in, string $out) {
    expect(PartyName::normalize($in))->toBe($out);
})->with([
    ['AL HAMD TEXTILE  ', 'AL HAMD TEXTILE'],
    ["N . N .  EXPORT INT ' L (PVT)  LTD", "N.N.EXPORT INT'L (PVT) LTD"],
    ['  Sheikhupura Textile Mills Limited', 'SHEIKHUPURA TEXTILE MILLS LIMITED'],
    ['U & I GARMENTS', 'U&I GARMENTS'],
    ['OMBRE - OTFTR', 'OMBRE-OTFTR'],
]);
