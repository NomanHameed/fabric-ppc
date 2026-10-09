<?php

namespace App\Modules\Activity\Concerns;

use App\Models\User;
use App\Modules\Activity\Models\UserAction;
use App\Modules\Activity\Services\ActionLogger;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Put this on a controller or service and recording an action is one line:
 *
 *     $this->recordAction(ActionKeys::COLOUR_APPROVED, $lot, ['lot' => $lot->lot_no_oracle, 'colour' => $lot->color_oracle_name]);
 */
trait RecordsUserActions
{
    /**
     * Record the action *after* the work has committed. ActionLogger swallows its own failures so a lost log
     * line cannot fail a completed operation; inside a transaction that guarantee would instead hide a
     * rollback, so a service with a transaction wraps this in `DB::afterCommit()`.
     *
     * @param  array<string, scalar|null>  $context
     * @param  CarbonInterface|null  $at  when it happened, when that is not "now" (a production date)
     */
    protected function recordAction(string $action, ?Model $subject = null, array $context = [], ?User $user = null, ?CarbonInterface $at = null): UserAction
    {
        return app(ActionLogger::class)->log($action, $subject, $context, $user, $at);
    }
}
