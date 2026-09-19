<?php

namespace App\Modules\OracleSync\Console;

use App\Models\User;
use App\Modules\OracleSync\Exceptions\InvalidOracleFile;
use App\Modules\OracleSync\Services\ImportCommitService;
use App\Modules\OracleSync\Services\ImportPreviewService;
use App\Modules\OracleSync\Sources\ExcelUploadSource;
use Illuminate\Console\Command;

class ImportOracleFile extends Command
{
    protected $signature = 'oracle:import {path : Oracle WIP export (.xlsx)} {--user= : User id recorded as uploader} {--commit : Commit when there are no errors}';

    protected $description = 'Preview (and optionally commit) an Oracle Processing Contract Status export';

    public function handle(ImportPreviewService $preview, ImportCommitService $commit): int
    {
        $user = User::query()->findOrFail($this->option('user') ?? User::query()->role('admin')->value('id'));
        try {
            $import = $preview->preview(new ExcelUploadSource($this->argument('path')), $user);
        } catch (InvalidOracleFile $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('New: %d  Changed: %d  Unchanged: %d  Missing: %d  Errors: %d',
            $import->new_count, $import->changed_count, $import->unchanged_count, $import->missing_count, $import->error_count));

        foreach ($import->unresolved_parties ?? [] as $p) {
            $this->warn("Unresolved Oracle party {$p['code']}: {$p['name']} ({$p['rows']} rows)");
        }

        if (! $this->option('commit')) {
            return self::SUCCESS;
        }
        if ($import->error_count > 0) {
            $this->error('Not committed: resolve the errors in the Oracle Import screen (import #'.$import->id.').');

            return self::FAILURE;
        }
        $commit->commit($import, $user);
        $this->info("Committed import #{$import->id}.");

        return self::SUCCESS;
    }
}
