<?php

use App\Models\User;
use App\Modules\Foundation\Models\Party;
use App\Modules\OracleSync\Jobs\CommitOracleImport;
use App\Modules\OracleSync\Services\ImportPreviewService;
use App\Modules\OracleSync\Sources\ExcelUploadSource;
use App\Modules\Production\Models\Lot;
use Illuminate\Support\Facades\Auth;

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
