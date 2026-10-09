<?php

use App\Models\User;
use App\Modules\Foundation\Models\Party;
use App\Modules\Foundation\Models\Quality;
use App\Modules\OracleSync\Events\LotChanged;
use App\Modules\OracleSync\Events\LotImported;
use App\Modules\OracleSync\Models\OracleImport;
use App\Modules\OracleSync\Services\ImportCommitService;
use App\Modules\OracleSync\Services\ImportPreviewService;
use App\Modules\OracleSync\Sources\ExcelUploadSource;
use App\Modules\Production\Models\Contract;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\LotBalance;
use App\Modules\Production\Models\LotMovement;
use Database\Seeders\PartySeeder;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->seed(PartySeeder::class);
    $this->user = User::factory()->create();
    $this->source = fn () => new ExcelUploadSource(base_path('tests/Fixtures/oracle-wip-2026-09.xlsx'), 'wip.xlsx');
    $this->preview = app(ImportPreviewService::class);
    $this->commit = app(ImportCommitService::class);
    $this->resolveAll = function (OracleImport $import): OracleImport {
        foreach ([397 => 'U&I GARMENTS', 485 => 'IMAGE', 561 => 'AL HAMD TEXTILE', 562 => 'LENOK CREATIONS'] as $code => $name) {
            $import = $this->preview->resolveParty($import, $code, ['mode' => 'link', 'party_id' => Party::findByName($name)->id]);
        }

        return $import;
    };
});

it('previews the export and lists the four unmatched Oracle parties', function () {
    $import = $this->preview->preview(($this->source)(), $this->user);
    expect($import->status)->toBe('previewed')
        ->and($import->new_count)->toBe(471)
        ->and($import->error_count)->toBe(152)
        ->and(collect($import->unresolved_parties)->pluck('code')->sort()->values()->all())->toBe([397, 485, 561, 562])
        ->and(collect($import->unresolved_parties)->firstWhere('code', 485)['rows'])->toBe(74);
});

it('links a party, stores the code and alias, and re-classifies its rows', function () {
    $import = $this->preview->preview(($this->source)(), $this->user);
    $image = Party::findByName('IMAGE');
    $import = $this->preview->resolveParty($import, 485, ['mode' => 'link', 'party_id' => $image->id]);
    expect($import->error_count)->toBe(78)->and($import->new_count)->toBe(545)
        ->and($image->fresh()->oracle_code)->toBe(485)
        ->and(Party::findByName('IMAGE PAKISTAN LIMITED')?->id)->toBe($image->id);
});

it('can create a new party for an unmatched code', function () {
    $import = $this->preview->preview(($this->source)(), $this->user);
    $import = $this->preview->resolveParty($import, 562, ['mode' => 'create', 'type' => 'brand']);
    expect(Party::where('oracle_code', 562)->value('name'))->toBe('LENOK CREATIONS (PVT) LTD')
        ->and($import->error_count)->toBe(143);
});

it('refuses to commit while errors remain', function () {
    $import = $this->preview->preview(($this->source)(), $this->user);
    $this->commit->commit($import, $this->user);
})->throws(DomainException::class);

it('commits contracts, qualities and lots and fires LotImported after commit', function () {
    Event::fake([LotImported::class, LotChanged::class]);
    $import = ($this->resolveAll)($this->preview->preview(($this->source)(), $this->user));
    expect($import->new_count)->toBe(623)->and($import->error_count)->toBe(0);

    $this->commit->commit($import, $this->user);

    expect($import->fresh()->status)->toBe('committed')
        ->and(Lot::count())->toBe(623)
        ->and(Contract::count())->toBe(167)
        ->and(Quality::count())->toBe(111)
        ->and(Lot::where('oracle_line_id', 47090)->first()->greige_received_oracle)->toBe('3650.00')
        ->and(Lot::where('lot_no_oracle', '264899')->count())->toBe(2)
        // every imported lot has its STOCK balance and import movement from the same transaction
        ->and(LotBalance::count())->toBe(623)->and(LotMovement::where('kind', 'import')->count())->toBe(623)
        ->and(Lot::where('oracle_line_id', 47090)->first()->greige_in_stock_oracle)->toBe('3650.00')
        ->and(LotMovement::whereNull('user_id')->count())->toBe(0);
    Event::assertDispatchedTimes(LotImported::class, 623);
    Event::assertDispatched(LotImported::class, fn ($e) => $e->lot->oracle_line_id === 47090 && $e->lot->greige_in_stock_oracle === '3650.00');
});

it('detects unchanged, changed and missing lots on re-import', function () {
    Event::fake([LotImported::class, LotChanged::class]);
    $this->commit->commit(($this->resolveAll)($this->preview->preview(($this->source)(), $this->user)), $this->user);

    Lot::where('oracle_line_id', 47090)->update(['greige_received_oracle' => 1]);
    $extra = Lot::factory()->create(['oracle_line_id' => 1, 'lot_no_oracle' => '199999', 'contract_id' => Contract::first()->id,
        'quality_id' => Quality::first()->id, 'po_type_oracle' => 'DYED', 'greige_received_oracle' => 10]);

    $second = $this->preview->preview(($this->source)(), $this->user);
    expect([$second->new_count, $second->changed_count, $second->unchanged_count, $second->missing_count, $second->error_count])
        ->toBe([0, 1, 622, 1, 0]);
    expect($second->rows()->where('action', 'changed')->first()->diff)->toBe(['greige_received_oracle' => [1.0, 3650.0]]);

    $this->commit->commit($second, $this->user);
    expect(Lot::where('oracle_line_id', 47090)->first()->greige_received_oracle)->toBe('3650.00')
        ->and($extra->fresh()->oracle_missing_at)->not->toBeNull()
        // the greige change (1 → 3650, edited outside the ledger) was posted as one STOCK adjustment
        ->and(LotMovement::where('kind', 'adjustment')->count())->toBe(1)
        ->and(LotMovement::where('kind', 'adjustment')->value('qty_into_to'))->toBe('3649.00');
    Event::assertDispatched(LotChanged::class, fn ($e) => $e->lot->oracle_line_id === 47090 && $e->diff === ['greige_received_oracle' => [1.0, 3650.0]]);
});

it('rejects committing a stale preview', function () {
    $a = ($this->resolveAll)($this->preview->preview(($this->source)(), $this->user));
    $this->travel(1)->seconds();
    $b = $this->preview->preview(($this->source)(), $this->user);
    $this->commit->commit($b, $this->user);
    $this->commit->commit($a, $this->user);
})->throws(DomainException::class);

it('stores the oracle code on name-matched parties at commit', function () {
    $this->commit->commit(($this->resolveAll)($this->preview->preview(($this->source)(), $this->user)), $this->user);
    expect(Party::findByName('BATIK')->oracle_code)->toBe(417)
        ->and(Party::findByName('SHEIKHUPURA TEXTILE MILLS LIMITED')->oracle_code)->toBe(255);
});

it('never commits twice', function () {
    $import = ($this->resolveAll)($this->preview->preview(($this->source)(), $this->user));
    $this->commit->commit($import, $this->user);
    $this->commit->commit($import->fresh(), $this->user);
})->throws(DomainException::class);
