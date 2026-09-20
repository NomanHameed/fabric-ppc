<?php

namespace App\Modules\Production\Console;

use App\Models\User;
use App\Modules\OracleSync\Services\ImportCommitService;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Services\LotIntakeService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Spatie\Permission\Exceptions\RoleDoesNotExist;

class BackfillLedger extends Command
{
    protected $signature = 'production:backfill-ledger {--user= : User id recorded on the movements}';

    protected $description = 'Give every lot imported before the ledger existed its route and its STOCK balance (idempotent)';

    public function handle(LotIntakeService $intake): int
    {
        $userId = $this->option('user');
        if ($userId === null) {
            try {
                $userId = User::query()->role('admin')->value('id');
            } catch (RoleDoesNotExist) {
                $userId = null;
            }
        }
        $user = $userId === null ? null : User::query()->whereKey($userId)->first();
        if ($user === null) {
            $this->error(__('production.errors.no_cli_user'));

            return self::FAILURE;
        }

        $done = 0;
        $skipped = 0;
        ImportCommitService::asAuditUser($user, function () use ($intake, $user, &$done, &$skipped): void {
            Lot::query()->whereDoesntHave('movements', fn ($q) => $q->where('kind', 'import'))->orderBy('id')
                ->chunkById(200, function (Collection $lots) use ($intake, $user, &$done, &$skipped): void {
                    foreach ($lots as $lot) {
                        if ($intake->receive($lot, $user->id) === null) {
                            $skipped++; // no greige to receive (the route is still assigned)
                        } else {
                            $done++;
                        }
                    }
                });
        });
        $this->info("Backfilled {$done} lot(s)".($skipped > 0 ? ", {$skipped} without greige left as they are." : '.'));

        return self::SUCCESS;
    }
}
