<?php

use App\Models\User;
use App\Modules\Foundation\Models\Party;
use App\Modules\OracleSync\Models\OracleImportRow;
use App\Modules\OracleSync\Services\ImportCommitService;
use App\Modules\OracleSync\Services\ImportPreviewService;
use App\Modules\OracleSync\Sources\ExcelUploadSource;
use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\LotMovement;
use App\Modules\Production\Services\LedgerService;
use App\Modules\Production\Services\LotIntakeService;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RouteTemplateSeeder;

beforeEach(function () {
    $this->seed([FoundationSeeder::class, RouteTemplateSeeder::class]);
    Party::create(['name' => 'NORMAL PARTY', 'type' => 'brand', 'oracle_code' => 1]);
    $this->user = User::factory()->create();
    $this->ledger = app(LedgerService::class);
    $this->preview = fn (array $rows) => app(ImportPreviewService::class)->preview(new ExcelUploadSource(buildOracleXlsx($rows)), $this->user);
    $this->commit = fn (array $rows) => app(ImportCommitService::class)->commit(($this->preview)($rows), $this->user);
});

it('gives an imported lot its route and STOCK balance inside the commit', function () {
    ($this->commit)([oracleRow(['QNTY' => 250, 'PROCESS_TYPE' => 'DYED'])]);
    $lot = Lot::where('oracle_line_id', 9000)->firstOrFail();
    $movement = $lot->movements()->first();
    expect($this->ledger->balances($lot))->toBe(['STOCK' => 250.0])
        ->and((float) $lot->greige_in_stock_oracle)->toBe(250.0)
        ->and($lot->routeSteps()->count())->toBe(7)
        ->and($movement->kind)->toBe('import')
        ->and($movement->source_type)->toBe((new OracleImportRow)->getMorphClass())
        ->and($movement->source_id)->toBe(OracleImportRow::where('oracle_line_id', 9000)->value('id'))
        ->and($movement->user_id)->toBe($this->user->id)
        ->and($movement->moved_at->equalTo($lot->created_at))->toBeTrue();
    $this->ledger->assertInvariant($lot);
});

it('rolls the lot back with the commit when the intake fails', function () {
    LotMovement::creating(fn () => throw new RuntimeException('ledger down'));
    $import = ($this->preview)([oracleRow(['QNTY' => 250])]);
    expect(fn () => app(ImportCommitService::class)->commit($import, $this->user))->toThrow(RuntimeException::class)
        ->and(Lot::where('oracle_line_id', 9000)->exists())->toBeFalse()
        ->and($import->fresh()->status)->toBe('failed');
});

it('posts an adjustment when a later export changes greige_received_oracle', function () {
    ($this->commit)([oracleRow(['QNTY' => 250])]);
    $this->travel(1)->seconds();
    ($this->commit)([oracleRow(['QNTY' => 300])]);
    $lot = Lot::where('oracle_line_id', 9000)->firstOrFail();
    $up = $lot->movements()->where('kind', 'adjustment')->first();
    expect($this->ledger->balances($lot))->toBe(['STOCK' => 300.0])
        ->and($up->from_position)->toBeNull()->and($up->to_position)->toBe('STOCK')->and($up->qty_into_to)->toBe('50.00')
        ->and($up->reason)->toBe(__('production.reasons.greige_changed', ['old' => '250.00', 'new' => '300.00']))
        ->and($up->user_id)->toBe($this->user->id)
        ->and($up->source_type)->toBe((new OracleImportRow)->getMorphClass());
    $this->travel(1)->seconds();
    ($this->commit)([oracleRow(['QNTY' => 280])]);
    $down = $lot->movements()->where('kind', 'adjustment')->get()->last();
    expect($this->ledger->balances($lot->fresh()))->toBe(['STOCK' => 280.0])
        ->and($down->from_position)->toBe('STOCK')->and($down->to_position)->toBeNull()->and($down->qty_out_of_from)->toBe('20.00')
        ->and($lot->routeSteps()->count())->toBe(7);
    $this->ledger->assertInvariant($lot);
});

it('flags a greige reduction below the issued quantity in the preview', function () {
    ($this->commit)([oracleRow(['QNTY' => 250])]);
    $lot = Lot::where('oracle_line_id', 9000)->firstOrFail();
    $this->ledger->post($lot, 'issue', Position::stock(), Position::issued(), 200, 200);
    $this->travel(1)->seconds();
    $import = ($this->preview)([oracleRow(['QNTY' => 150])]);
    expect($import->error_count)->toBe(1)->and($import->changed_count)->toBe(0)
        ->and($import->rows()->first()->message)->toStartWith('greige_below_issued:')
        ->and($import->rows()->first()->lot_id)->toBe($lot->id)
        ->and(fn () => app(ImportCommitService::class)->commit($import, $this->user))->toThrow(DomainException::class);
    // exactly what is still in STOCK may be taken back
    $ok = ($this->preview)([oracleRow(['QNTY' => 200])]);
    expect($ok->error_count)->toBe(0)->and($ok->changed_count)->toBe(1);
});

it('receives a lot the ledger has never seen when its greige changes, instead of adjusting an empty STOCK', function () {
    ($this->commit)([oracleRow(['QNTY' => 250])]);
    $lot = Lot::where('oracle_line_id', 9000)->firstOrFail();
    // simulate a lot imported before the ledger existed
    $lot->movements()->delete();
    $lot->balances()->delete();
    Lot::whereKey($lot->id)->update(['greige_in_stock_oracle' => 0, 'last_movement_at' => null]);
    $this->travel(1)->seconds();
    ($this->commit)([oracleRow(['QNTY' => 100])]); // a reduction: no error row, and STOCK never goes negative
    expect($this->ledger->balances($lot))->toBe(['STOCK' => 100.0])
        ->and($lot->movements()->pluck('kind')->all())->toBe(['import']);
});

it('receive() is idempotent', function () {
    $lot = Lot::factory()->create(['greige_received_oracle' => 10]);
    $intake = app(LotIntakeService::class);
    expect($intake->receive($lot, $this->user->id))->not->toBeNull()
        ->and($intake->receive($lot, $this->user->id))->toBeNull()
        ->and($lot->movements()->count())->toBe(1)->and($this->ledger->balances($lot))->toBe(['STOCK' => 10.0])
        ->and($lot->routeSteps()->count())->toBe(7);
});

it('receive() gives a lot without greige its route but no movement, until greige arrives', function () {
    $lot = Lot::factory()->create(['greige_received_oracle' => 0]);
    $intake = app(LotIntakeService::class);
    expect($intake->receive($lot, $this->user->id))->toBeNull()
        ->and($lot->movements()->count())->toBe(0)->and($lot->routeSteps()->count())->toBe(7);
    $lot->update(['greige_received_oracle' => 40]);
    $m = $intake->adjustGreige($lot, 0, 40, $this->user->id);
    expect($m?->kind)->toBe('import')->and($this->ledger->balances($lot))->toBe(['STOCK' => 40.0]);
});
