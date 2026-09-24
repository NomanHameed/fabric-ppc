<?php

namespace App\Modules\Activity\Concerns;

use App\Models\User;
use App\Modules\Activity\Models\UserAction;
use App\Modules\Activity\Services\ActionLogger;
use Illuminate\Database\Eloquent\Model;

/**
 * Put this on a controller or service and recording an action is one line:
 *
 *     $this->recordAction(ActionKeys::COLOUR_APPROVED, $lot, ['lot' => $lot->lot_no, 'colour' => $lot->colour]);
 */
trait RecordsUserActions
{
    /** @param array<string, scalar|null> $context */
    protected function recordAction(string $action, ?Model $subject = null, array $context = [], ?User $user = null): UserAction
    {
        return app(ActionLogger::class)->log($action, $subject, $context, $user);
    }
}
