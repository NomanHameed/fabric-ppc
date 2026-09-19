<?php

namespace App\Modules\OracleSync\Jobs;

use App\Models\User;
use App\Modules\OracleSync\Models\OracleImport;
use App\Modules\OracleSync\Services\ImportCommitService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class CommitOracleImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly int $importId, public readonly int $userId) {}

    public function handle(ImportCommitService $service): void
    {
        $import = OracleImport::query()->findOrFail($this->importId);
        $user = User::query()->findOrFail($this->userId);

        // The worker is the console: switch auditing on and act as the committing user so lot/contract audits get user_id.
        ImportCommitService::asAuditUser($user, fn () => $service->commit($import, $user));
    }

    /** Called by the queue when the job dies (timeout, worker crash, uncaught error): never leave the import stuck. */
    public function failed(?Throwable $e): void
    {
        OracleImport::query()->whereKey($this->importId)->where('status', 'committing')
            ->update(['status' => 'failed', 'error_message' => $e?->getMessage() ?? __('oracle.status.failed')]);
    }
}
