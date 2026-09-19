<?php

namespace Database\Seeders;

use App\Modules\Foundation\Models\Party;
use App\Modules\Foundation\Support\PartyName;
use Illuminate\Database\Seeder;

class PartySeeder extends Seeder
{
    /** floor shorthand => party name as in parties.json */
    private const ALIASES = [
        'M-B' => 'MARIA-B',
        'MB' => 'MARIA-B',
        'SKP' => 'Sheikhupura Textile Mills Limited',
        'N-N' => "N . N .  EXPORT INT ' L (PVT)  LTD",
        'N.N EXPORT' => "N . N .  EXPORT INT ' L (PVT)  LTD",
        'Y-Z' => 'Y.Z CORPORATION',
        'ZARTEX' => 'ZAR TEX SAYA',
        'U&I' => 'U&I GARMENTS',
        'UGI' => 'U&I GARMENTS',
        'AL-HAMD' => 'AL HAMD TEXTILE',
    ];

    public function run(): void
    {
        $rows = json_decode(file_get_contents(database_path('seeders/data/parties.json')), true, flags: JSON_THROW_ON_ERROR);

        foreach ($rows as $row) {
            Party::query()->updateOrCreate(
                ['name_normalized' => PartyName::normalize($row['name'])],
                ['name' => trim($row['name']), 'type' => $row['type'] === 'local' ? 'local' : 'brand'],
            );
        }

        foreach (self::ALIASES as $alias => $partyName) {
            $party = Party::query()->where('name_normalized', PartyName::normalize($partyName))->first();
            if ($party !== null) {
                $party->aliases()->updateOrCreate(['alias_normalized' => PartyName::normalize($alias)], ['alias' => $alias]);
            }
        }
    }
}
