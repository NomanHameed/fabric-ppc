<?php

use App\Models\User;
use App\Modules\Foundation\Models\Party;
use App\Modules\OracleSync\Jobs\CommitOracleImport;
use App\Modules\OracleSync\Services\ImportCommitService;
use App\Modules\OracleSync\Services\ImportPreviewService;
use App\Modules\OracleSync\Sources\ExcelUploadSource;
use App\Modules\Production\Models\Contract;
use App\Modules\Production\Models\Lot;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Party::create(['name' => 'NORMAL PARTY', 'type' => 'brand', 'oracle_code' => 1]);
    $this->ppc = User::factory()->create()->assignRole('ppc');
    $this->preview = app(ImportPreviewService::class);
    $this->previewOf = fn (array $rows) => $this->preview->preview(new ExcelUploadSource(buildOracleXlsx($rows)), $this->ppc);
});

it('turns an unparseable row into an error row instead of aborting the preview (M-6)', function () {
    $import = ($this->previewOf)([oracleRow(['QNTY' => 'abc', 'PC_DTL_ID' => 9001]), oracleRow(['PC_DTL_ID' => 9002, 'LOT_NUM1' => 700002])]);
    $error = $import->rows()->where('action', 'error')->first();
    expect($import->error_count)->toBe(1)->and($import->new_count)->toBe(1)
        ->and($error->message)->toStartWith('bad_row:')->and($error->message)->toContain('QNTY')
        ->and($error->row_no)->toBe(2)->and($error->oracle_line_id)->toBe(9001)->and($error->payload)->toBeNull();
});

it('flags overlong values in the preview (M-6)', function () {
    $import = ($this->previewOf)([oracleRow(['DESIGN' => str_repeat('D', 61)])]);
    expect($import->error_count)->toBe(1)
        ->and($import->rows()->first()->message)->toStartWith('overlong:')
        ->and($import->rows()->first()->message)->toContain('design');
});

it('refuses to commit while another commit holds the lock (M-7)', function () {
    $import = ($this->previewOf)([oracleRow()]);
    $lock = Cache::lock('oracle-import-commit', 60);
    expect($lock->get())->toBeTrue();
    try {
        expect(fn () => app(ImportCommitService::class)->commit($import, $this->ppc, 0))
            ->toThrow(DomainException::class, __('oracle.errors.commit_in_progress'));
    } finally {
        $lock->release();
    }
    expect($import->fresh()->status)->toBe('previewed');
});

it('waits for the running commit to finish instead of failing at once', function () {
    $import = ($this->previewOf)([oracleRow()]);
    $lock = Cache::lock('oracle-import-commit', 1); // expires on its own: the waiting commit must pick it up
    expect($lock->get())->toBeTrue();
    app(ImportCommitService::class)->commit($import, $this->ppc, 3);
    expect($import->fresh()->status)->toBe('committed');
});

it('does not report a lot as missing when its row is in the file but unreadable', function () {
    app(ImportCommitService::class)->commit(($this->previewOf)([oracleRow(['PC_DTL_ID' => 9001])]), $this->ppc);
    $this->travel(1)->seconds();
    $import = ($this->previewOf)([oracleRow(['PC_DTL_ID' => 9001, 'QNTY' => 'abc'])]);
    expect($import->error_count)->toBe(1)->and($import->missing_count)->toBe(0)
        ->and($import->rows()->where('action', 'error')->value('oracle_line_id'))->toBe(9001);
});

it('records a bad row whose line id is not an unsigned integer without a line id', function () {
    $import = ($this->previewOf)([oracleRow(['PC_DTL_ID' => -5, 'QNTY' => 'abc']), oracleRow(['PC_DTL_ID' => 9002, 'LOT_NUM1' => 700002])]);
    $error = $import->rows()->where('action', 'error')->first();
    expect($import->status)->toBe('previewed')->and($import->error_count)->toBe(1)->and($import->new_count)->toBe(1)
        ->and($error->message)->toStartWith('bad_row:')->and($error->oracle_line_id)->toBeNull();
});

it('releases the lock after a commit, even one that fails', function () {
    $import = ($this->previewOf)([oracleRow()]);
    app(ImportCommitService::class)->commit($import, $this->ppc);
    expect($import->fresh()->status)->toBe('committed');

    $import->update(['status' => 'committing', 'error_count' => 1]);
    expect(fn () => app(ImportCommitService::class)->commit($import, $this->ppc))->toThrow(DomainException::class);

    $lock = Cache::lock('oracle-import-commit', 60);
    expect($lock->get())->toBeTrue();
    $lock->release();
});

it('reports not_previewed when re-committing a committed import', function () {
    $import = ($this->previewOf)([oracleRow()]);
    app(ImportCommitService::class)->commit($import, $this->ppc);
    $this->actingAs($this->ppc)->post("/oracle/imports/{$import->id}/commit")
        ->assertSessionHasErrors(['import' => __('oracle.errors.not_previewed')]);
});

it('reverts the claim when the job cannot be queued', function () {
    $import = ($this->previewOf)([oracleRow()]);
    $this->mock(Dispatcher::class)->shouldReceive('dispatch')->andThrow(new RuntimeException('redis down'));
    $this->actingAs($this->ppc)->post("/oracle/imports/{$import->id}/commit")
        ->assertSessionHasErrors(['import' => __('oracle.errors.queue_unavailable')]);
    expect($import->fresh()->status)->toBe('previewed');
});

it('flags and clears contracts.oracle_missing_at (M-8)', function () {
    $commit = app(ImportCommitService::class);
    $commit->commit(($this->previewOf)([oracleRow(['CONTRACT_NUM' => 700001, 'PC_DTL_ID' => 9001]), oracleRow(['CONTRACT_NUM' => 700002, 'LOT_NUM1' => 700002, 'PC_DTL_ID' => 9002])]), $this->ppc);
    $this->travel(1)->seconds();
    $commit->commit(($this->previewOf)([oracleRow(['CONTRACT_NUM' => 700001, 'PC_DTL_ID' => 9001])]), $this->ppc);
    expect(Contract::where('po_no', 700002)->value('oracle_missing_at'))->not->toBeNull()
        ->and(Contract::where('po_no', 700001)->value('oracle_missing_at'))->toBeNull();
    $this->travel(1)->seconds();
    $commit->commit(($this->previewOf)([oracleRow(['CONTRACT_NUM' => 700001, 'PC_DTL_ID' => 9001]), oracleRow(['CONTRACT_NUM' => 700002, 'LOT_NUM1' => 700002, 'PC_DTL_ID' => 9002])]), $this->ppc);
    expect(Contract::where('po_no', 700002)->value('oracle_missing_at'))->toBeNull();
});

it('flags a contract whose only open lot is missing even when it also has a closed lot', function () {
    $commit = app(ImportCommitService::class);
    $commit->commit(($this->previewOf)([oracleRow(['PC_DTL_ID' => 9001]), oracleRow(['LOT_NUM1' => 700002, 'PC_DTL_ID' => 9002])]), $this->ppc);
    Lot::query()->where('oracle_line_id', 9002)->update(['state' => 'closed']);
    $this->travel(1)->seconds();
    $commit->commit(($this->previewOf)([oracleRow(['CONTRACT_NUM' => 700002, 'LOT_NUM1' => 700003, 'PC_DTL_ID' => 9003])]), $this->ppc);
    expect(Contract::where('po_no', 700001)->value('oracle_missing_at'))->not->toBeNull();
    $this->travel(1)->seconds();
    $commit->commit(($this->previewOf)([oracleRow(['PC_DTL_ID' => 9001]), oracleRow(['CONTRACT_NUM' => 700002, 'LOT_NUM1' => 700003, 'PC_DTL_ID' => 9003])]), $this->ppc);
    expect(Contract::where('po_no', 700001)->value('oracle_missing_at'))->toBeNull();
});

it('defaults the redis retry_after above the commit timeout (M-9)', function () {
    expect(config('queue.connections.redis.retry_after'))->toBeGreaterThanOrEqual(660)
        ->and((new CommitOracleImport(1, 1))->timeout)->toBeLessThan(660);
});
