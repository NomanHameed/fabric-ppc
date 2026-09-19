<?php

use App\Models\User;
use App\Modules\Foundation\Models\Party;
use App\Modules\OracleSync\Jobs\CommitOracleImport;
use App\Modules\OracleSync\Models\OracleImport;
use App\Modules\OracleSync\Services\ImportCommitService;
use App\Modules\OracleSync\Services\ImportPreviewService;
use App\Modules\OracleSync\Sources\ExcelUploadSource;
use App\Modules\Production\Models\Contract;
use App\Modules\Production\Models\Lot;
use Database\Seeders\PartySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Party::create(['name' => 'NORMAL PARTY', 'type' => 'brand', 'oracle_code' => 1]);
    $this->uploader = User::factory()->create();
    $this->committer = User::factory()->create();
    $this->preview = app(ImportPreviewService::class);
    $this->smallImport = fn () => $this->preview->preview(new ExcelUploadSource(buildOracleXlsx([
        oracleRow(['CONTRACT_NUM' => 800001, 'LOT_NUM1' => 800001, 'PC_DTL_ID' => 9801]),
        oracleRow(['CONTRACT_NUM' => 800001, 'LOT_NUM1' => 800002, 'PC_DTL_ID' => 9802]),
    ])), $this->uploader);
});

it('audits the lots a queued commit creates, attributed to the committing user', function () {
    config(['audit.console' => false]);
    $import = ($this->smallImport)();

    CommitOracleImport::dispatchSync($import->id, $this->committer->id);

    $lot = Lot::where('oracle_line_id', 9801)->firstOrFail();
    $audit = $lot->audits()->first();
    expect($import->fresh()->status)->toBe('committed')
        ->and($audit)->not->toBeNull()
        ->and($audit->event)->toBe('created')
        ->and($audit->user_id)->toBe($this->committer->id)
        // The worker is long-lived: the job must not leave auditing or the user switched on for later jobs.
        ->and(config('audit.console'))->toBeFalse()
        ->and(Auth::user())->toBeNull();
});

it('rejects committing a stale preview from the screen with an import error', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $ppc = User::factory()->create()->assignRole('ppc');
    $stale = ($this->smallImport)();
    $this->travel(1)->seconds();
    app(ImportCommitService::class)->commit(($this->smallImport)(), $this->uploader);
    Queue::fake();

    $this->actingAs($ppc)->post("/oracle/imports/{$stale->id}/commit")
        ->assertSessionHasErrors(['import' => __('oracle.errors.stale')]);

    expect($stale->fresh()->status)->toBe('previewed');
    Queue::assertNothingPushed();
});

it('flips the import to committing before queueing the commit, and refuses a second request', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $ppc = User::factory()->create()->assignRole('ppc');
    $import = ($this->smallImport)();
    Queue::fake();

    $this->actingAs($ppc)->post("/oracle/imports/{$import->id}/commit")->assertSessionHasNoErrors()->assertRedirect();

    expect($import->fresh()->status)->toBe('committing');
    Queue::assertPushed(CommitOracleImport::class, fn ($job) => $job->importId === $import->id && $job->userId === $ppc->id);

    $this->actingAs($ppc)->post("/oracle/imports/{$import->id}/commit")
        ->assertSessionHasErrors(['import' => __('oracle.errors.not_previewed')]);
    Queue::assertPushed(CommitOracleImport::class, 1);
});

it('marks a committing import failed when the job fails', function () {
    $import = ($this->smallImport)();
    $import->update(['status' => 'committing']);

    (new CommitOracleImport($import->id, $this->committer->id))->failed(new RuntimeException('worker died'));

    expect($import->fresh())->status->toBe('failed')->error_message->toBe('worker died');
});

it('leaves a finished import alone when a late failure is reported', function () {
    $import = ($this->smallImport)();
    $import->update(['status' => 'committed', 'committed_at' => now()]);

    (new CommitOracleImport($import->id, $this->committer->id))->failed(new RuntimeException('late'));

    expect($import->fresh())->status->toBe('committed')->error_message->toBeNull();
});

it('records a failure when an import already marked committing fails its pre-checks', function () {
    $import = ($this->smallImport)();
    $import->update(['status' => 'committing', 'error_count' => 1]);

    expect(fn () => CommitOracleImport::dispatchSync($import->id, $this->committer->id))->toThrow(DomainException::class);

    expect($import->fresh())->status->toBe('failed')->error_message->toBe(__('oracle.errors.has_errors'));
});

it('rolls the whole commit back when a row fails part-way', function () {
    $import = $this->preview->preview(new ExcelUploadSource(buildOracleXlsx([
        oracleRow(['CONTRACT_NUM' => 810001, 'LOT_NUM1' => 810001, 'PC_DTL_ID' => 9811]),
        oracleRow(['CONTRACT_NUM' => 810002, 'LOT_NUM1' => 810002, 'PC_DTL_ID' => 9812]),
        oracleRow(['CONTRACT_NUM' => 810003, 'LOT_NUM1' => 810003, 'PC_DTL_ID' => 9813]),
    ])), $this->uploader);
    $created = 0;
    Lot::creating(function () use (&$created) {
        if (++$created === 2) {
            throw new RuntimeException('boom on the second lot');
        }
    });

    expect(fn () => app(ImportCommitService::class)->commit($import, $this->committer))->toThrow(RuntimeException::class);

    expect(Lot::count())->toBe(0)
        ->and(Contract::count())->toBe(0)
        ->and($import->fresh())->status->toBe('failed')->error_message->toBe('boom on the second lot');
});

it('runs a queued commit of the full Oracle export end to end', function () {
    $this->seed(PartySeeder::class);
    $import = $this->preview->preview(new ExcelUploadSource(base_path('tests/Fixtures/oracle-wip-2026-09.xlsx'), 'wip.xlsx'), $this->uploader);
    foreach ([397 => 'U&I GARMENTS', 485 => 'IMAGE', 561 => 'AL HAMD TEXTILE', 562 => 'LENOK CREATIONS'] as $code => $name) {
        $import = $this->preview->resolveParty($import, $code, ['mode' => 'link', 'party_id' => Party::findByName($name)->id]);
    }
    expect($import->error_count)->toBe(0);
    OracleImport::query()->whereKey($import->id)->update(['status' => 'committing']); // as the controller leaves it

    CommitOracleImport::dispatchSync($import->id, $this->committer->id);

    expect($import->fresh())->status->toBe('committed')->committed_at->not->toBeNull()
        ->and(Lot::count())->toBe(623)
        ->and(Contract::count())->toBe(167);
});
