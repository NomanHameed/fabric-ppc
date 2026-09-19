<?php

namespace App\Modules\OracleSync\Services;

use App\Modules\Foundation\Models\Party;
use App\Modules\Foundation\Support\PartyName;
use Illuminate\Validation\ValidationException;

final class PartyResolver
{
    /** @var array<int, ?Party> */
    private array $cache = [];

    public function resolve(int $code, string $name): ?Party
    {
        if (array_key_exists($code, $this->cache) && $this->cache[$code] !== null) {
            return $this->cache[$code];
        }

        $party = Party::query()->where('oracle_code', $code)->first() ?? Party::findByName($name);

        // A name match that already belongs to a different Oracle code is not a match.
        if ($party !== null && $party->oracle_code !== null && $party->oracle_code !== $code) {
            $party = null;
        }

        return $this->cache[$code] = $party;
    }

    public function link(int $code, string $oracleName, Party $party): Party
    {
        if ($party->oracle_code !== null && $party->oracle_code !== $code) {
            throw ValidationException::withMessages(['party_id' => __('oracle.errors.code_conflict', ['code' => $party->oracle_code])]);
        }
        $party->oracle_code = $code;
        $party->save();
        if (PartyName::normalize($oracleName) !== $party->name_normalized) {
            $party->aliases()->firstOrCreate(['alias_normalized' => PartyName::normalize($oracleName)], ['alias' => trim($oracleName)]);
        }
        $this->cache[$code] = $party;

        return $party;
    }

    public function create(int $code, string $name, string $type): Party
    {
        return $this->cache[$code] = Party::query()->create(['oracle_code' => $code, 'name' => trim($name), 'type' => $type]);
    }

    public function forget(): void
    {
        $this->cache = [];
    }
}
