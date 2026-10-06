<?php

namespace App\Modules\Production\Services;

use App\Models\User;
use App\Modules\Activity\Models\UserAction;
use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\ColourOverride;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\LotMovement;
use App\Modules\Production\Models\LotRouteStep;
use App\Modules\Production\Timeline\TimelineEvent;
use App\Modules\Production\Timeline\TimelineEventType;
use Illuminate\Support\Collection;
use OwenIt\Auditing\Models\Audit;

/**
 * The lot timeline — "loaded", "moved to dyeing", "approved by X" — as a read-model over records that already
 * exist, not as a second event table.
 *
 * Why a projection and not an event table:
 *
 *  - Every location-and-quantity fact on this timeline is already a `lot_movements` row, and LedgerService is
 *    the sole writer of those. A parallel `lot_timeline_events` table would have to be written next to every
 *    ledger post, which means two writers for one fact and a real chance of the two disagreeing after a
 *    reversal, a rebuild or a backfill. The brief's own constraint — do not duplicate the ledger's authority —
 *    is exactly what an event table would breach.
 *  - The non-ledger facts already have owners too: lot state and colour status changes are in `audits` (with
 *    actor and timestamp, which is what a timeline needs), colour overrides are in `colour_overrides`.
 *  - Anything with no other home — a business action a user took that moved no metres and changed no column —
 *    goes to `user_actions` through ActionLogger, and is picked up here by subject. That is the extension
 *    point an event table would otherwise have been, and it is shared with the rest of the application.
 *
 * The cost is that this cannot be queried across lots in SQL. The brief asks for a timeline per lot, and a
 * lot has tens of events, so the per-lot assembly below is a handful of indexed queries.
 */
final class LotTimelineService
{
    /**
     * @param  list<string>  $types  restrict to these TimelineEventType values (all when empty)
     * @return Collection<int, TimelineEvent>
     */
    public function for(Lot $lot, array $types = [], ?int $limit = null): Collection
    {
        $labels = $this->positionLabels($lot);

        $events = $this->fromMovements($lot, $labels)
            ->concat($this->fromAudits($lot))
            ->concat($this->fromColourOverrides($lot))
            ->concat($this->fromUserActions($lot));

        if ($types !== []) {
            $events = $events->filter(fn (TimelineEvent $e) => in_array($e->type, $types, true));
        }

        $events = $events
            ->sortBy([fn (TimelineEvent $a, TimelineEvent $b) => $a->occurredAt <=> $b->occurredAt, fn ($a, $b) => $a->id <=> $b->id])
            ->values();

        return $limit === null ? $events : $events->take($limit)->values();
    }

    /**
     * @param  list<string>  $types
     * @return list<array<string, mixed>>
     */
    public function toArray(Lot $lot, array $types = [], ?int $limit = null): array
    {
        return array_values($this->for($lot, $types, $limit)->map(fn (TimelineEvent $e) => $e->toArray())->all());
    }

    // ---- sources ---------------------------------------------------------------------------------------

    /**
     * @param  array<string, string>  $labels
     * @return Collection<int, TimelineEvent>
     */
    private function fromMovements(Lot $lot, array $labels): Collection
    {
        return LotMovement::query()->where('lot_id', $lot->getKey())
            ->with('user:id,name')->orderBy('moved_at')->orderBy('id')->get()
            ->map(function (LotMovement $m) use ($labels): TimelineEvent {
                $from = $m->from_position === null ? null : ($labels[$m->from_position] ?? $m->from_position);
                $to = $m->to_position === null ? null : ($labels[$m->to_position] ?? $m->to_position);

                return new TimelineEvent(
                    id: 'movement:'.$m->id,
                    type: $this->movementType($m),
                    occurredAt: $m->moved_at,
                    from: $from,
                    to: $to,
                    qty: (float) ($m->to_position !== null ? $m->qty_into_to : $m->qty_out_of_from),
                    actorId: $m->user_id,
                    actorName: $m->user?->name,
                    note: $m->reason,
                    context: ['kind' => $this->line('production.kinds.'.$m->kind)],
                );
            });
    }

    /**
     * Lot state and colour status, from the model's own audit trail — the record that already carries who
     * changed it and when. The ledger-derived columns are excluded: they are this timeline's other half.
     *
     * @return Collection<int, TimelineEvent>
     */
    private function fromAudits(Lot $lot): Collection
    {
        $watched = ['state' => TimelineEventType::STATE_CHANGED, 'colour_status' => TimelineEventType::COLOUR_STATUS_CHANGED];

        $audits = Audit::query()
            ->where('auditable_type', $lot->getMorphClass())
            ->where('auditable_id', $lot->getKey())
            ->where('event', 'updated')
            ->orderBy('created_at')->orderBy('id')->get();

        // Audit::user() is a morphTo the analyser cannot follow, so the actors are fetched in one query here.
        $actors = User::query()
            ->whereIn('id', $audits->map(fn (Audit $a) => $a->getAttribute('user_id'))->filter()->unique()->all())
            ->pluck('name', 'id');

        return $audits
            ->flatMap(function (Audit $audit) use ($watched, $lot, $actors): array {
                /** @var array<string, mixed> $new */
                $new = (array) $audit->new_values;
                /** @var array<string, mixed> $old */
                $old = (array) $audit->old_values;
                $events = [];

                foreach ($watched as $column => $type) {
                    if (! array_key_exists($column, $new)) {
                        continue;
                    }
                    $reason = $column === 'state'
                        ? ($new['hold_reason'] ?? $new['cancel_reason'] ?? null)
                        : null;
                    $actorId = $audit->getAttribute('user_id');
                    $actorId = is_numeric($actorId) ? (int) $actorId : null;

                    $events[] = new TimelineEvent(
                        id: 'audit:'.$audit->id.':'.$column,
                        type: $type,
                        occurredAt: $audit->created_at,
                        from: $this->scalar($old[$column] ?? null),
                        to: $this->scalar($new[$column] ?? null),
                        actorId: $actorId,
                        actorName: $this->scalar($actors[$actorId] ?? null),
                        note: is_string($reason) ? $reason : null,
                        context: ['lot' => $lot->lot_no],
                    );
                }

                return $events;
            });
    }

    /** @return Collection<int, TimelineEvent> */
    private function fromColourOverrides(Lot $lot): Collection
    {
        return ColourOverride::query()->where('lot_id', $lot->getKey())
            ->with('approver:id,name')->orderBy('approved_at')->get()
            ->map(fn (ColourOverride $o) => new TimelineEvent(
                id: 'colour_override:'.$o->id,
                type: TimelineEventType::COLOUR_OVERRIDE_APPROVED,
                occurredAt: $o->approved_at,
                actorId: $o->approved_by,
                actorName: $o->approver?->name,
                note: $o->reason,
                context: ['lot' => $lot->lot_no],
            ));
    }

    /**
     * Business actions recorded against this lot that moved no metres and changed no column. This is where an
     * event table's rows would have gone; they go through ActionLogger instead, shared with the action log.
     *
     * @return Collection<int, TimelineEvent>
     */
    private function fromUserActions(Lot $lot): Collection
    {
        return UserAction::query()->forSubject($lot)->with('user:id,name')
            ->orderBy('occurred_at')->orderBy('id')->get()
            ->map(fn (UserAction $a) => new TimelineEvent(
                id: 'action:'.$a->id,
                type: TimelineEventType::ACTION,
                occurredAt: $a->occurred_at,
                actorId: $a->user_id,
                actorName: $a->user?->name,
                note: $a->computer_name,
                context: ['description' => $a->label()],
            ));
    }

    // ---- helpers ---------------------------------------------------------------------------------------

    private function movementType(LotMovement $m): string
    {
        if ($m->reversal_of_id !== null) {
            return TimelineEventType::REVERSED;
        }

        return match ($m->kind) {
            'import' => TimelineEventType::LOADED,
            'issue' => TimelineEventType::ISSUED,
            'process' => $m->from_position === $m->to_position ? TimelineEventType::PROCESSED : TimelineEventType::MOVED,
            'reprocess' => TimelineEventType::REPROCESSED,
            'dispatch' => TimelineEventType::DISPATCHED,
            'loss' => TimelineEventType::LOSS,
            default => TimelineEventType::ADJUSTED,
        };
    }

    /**
     * Position value => label. STEP:<id> becomes the operation of that route step, which is what "moved to
     * dyeing" actually means; the fixed positions use the labels the rest of Production already uses.
     *
     * @return array<string, string>
     */
    private function positionLabels(Lot $lot): array
    {
        $labels = [];
        foreach ([Position::STOCK, Position::ISSUED, Position::FINISHED, Position::DISPATCHED, Position::LOSS] as $fixed) {
            $labels[$fixed] = __('production.positions.'.strtolower($fixed));
        }

        $steps = LotRouteStep::query()->where('lot_id', $lot->getKey())->with('operation:id,name')->get();
        foreach ($steps as $step) {
            $operation = $step->operation;
            $labels['STEP:'.$step->id] = $operation === null
                ? $this->line('production.positions.step')
                : $operation->name;
        }

        return $labels;
    }

    private function scalar(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    /** A lang line as a plain string: __() is declared as string|array|null, which no caller here wants. */
    private function line(string $key): string
    {
        $value = __($key);

        return is_string($value) ? $value : $key;
    }
}
