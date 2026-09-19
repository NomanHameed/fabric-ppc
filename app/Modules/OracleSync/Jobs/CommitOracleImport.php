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

class CommitOracleImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly int $importId, public readonly int $userId) {}

    public function handle(ImportCommitService $service): void
    {
        $service->commit(OracleImport::query()->findOrFail($this->importId), User::query()->findOrFail($this->userId));
    }
}
