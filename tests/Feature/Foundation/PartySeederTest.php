<?php

use App\Modules\Foundation\Models\Party;
use Database\Seeders\PartySeeder;

it('seeds unique parties with types', function () {
    $this->seed(PartySeeder::class);
    expect(Party::count())->toBe(124)
        ->and(Party::where('type', 'brand')->count())->toBeGreaterThan(70);
});

it('seeds floor shorthand aliases', function () {
    $this->seed(PartySeeder::class);
    expect(Party::findByName('M-B')?->name)->toBe('MARIA-B')
        ->and(Party::findByName('SKP')?->name_normalized)->toBe('SHEIKHUPURA TEXTILE MILLS LIMITED')
        ->and(Party::findByName('maria-b ')?->name)->toBe('MARIA-B')
        ->and(Party::findByName('NOBODY'))->toBeNull();
});

it('is idempotent', function () {
    $this->seed(PartySeeder::class);
    $this->seed(PartySeeder::class);
    expect(Party::count())->toBe(124);
});
