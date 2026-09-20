<?php

namespace App\Modules\Production\Services;

use App\Modules\Foundation\Models\Quality;
use App\Modules\Foundation\Models\RouteTemplate;
use App\Modules\Foundation\Models\RouteTemplateStep;
use App\Modules\Foundation\Models\Setting;
use App\Modules\Foundation\Routing\RouteTemplateMatcher;
use App\Modules\Production\Ledger\Position;
use App\Modules\Production\Models\Contract;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Models\LotMovement;
use App\Modules\Production\Models\LotRouteStep;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Planned route per lot (spec D6, §5.3 lot_route_steps). Positions between steps are derived here:
 * the metres for step N wait at previousPosition(N) and land at targetPosition(N); skipped steps are bypassed.
 *
 * Task 9's sheet service must post its process movements from previousPosition() to targetPosition(): the
 * "processed through" detection that drives step statuses relies on exactly that pair.
 */
final class LotRouteService
{
    public function __construct(private readonly RouteTemplateMatcher $matcher, private readonly LedgerService $ledger) {}

    /**
     * Assign the best-matching template (or $template) — falling back to the `default_route_template_id` setting.
     * Returns the number of steps created; 0 when the lot already has a route and $replace is false, or no template fits.
     */
    public function assign(Lot $lot, ?RouteTemplate $template = null, bool $replace = false): int
    {
        return DB::transaction(function () use ($lot, $template, $replace): int {
            $current = $lot->routeSteps()->get();
            if (! $replace && $current->isNotEmpty()) {
                return 0;
            }
            $quality = $lot->quality;
            $template ??= $this->matcher->match($lot->po_type, $quality instanceof Quality ? $quality->description : null) ?? $this->defaultTemplate();
            if ($template === null) {
                return 0;
            }
            if ($current->isNotEmpty()) {
                $this->assertNoProgress($lot);
                $current->each->delete();
            }
            $n = 0;
            $templateSteps = RouteTemplateStep::query()->where('route_template_id', $template->id)->orderBy('seq')->get();
            foreach ($templateSteps as $i => $step) {
                $lot->routeSteps()->create(['seq' => ($i + 1) * 10, 'operation_id' => $step->operation_id, 'is_added' => false]);
                $n++;
            }
            $lot->unsetRelation('routeSteps');

            return $n;
        });
    }

    /**
     * Replace the route with $steps = [['id' => existing id|null, 'operation_id' => int, 'is_added' => bool], ...] in order.
     * Steps with progress (done/partial, or metres waiting at their position) must be kept, in the same relative order,
     * and the edit may not move the positions they take from or land at (metres already sitting at STEP:<id> would be
     * orphaned). Removed steps are deleted with deleteOrFail(): a step referenced by a production line fails with an
     * FK violation that the controller maps to forms.in_use.
     *
     * @param  list<array{id?: int|null, operation_id: int, is_added?: bool}>  $steps
     */
    public function replace(Lot $lot, array $steps): void
    {
        DB::transaction(function () use ($lot, $steps): void {
            $existing = $lot->routeSteps()->get()->keyBy('id');
            $keptIds = array_values(array_filter(array_map(fn (array $s) => $s['id'] ?? null, $steps)));
            $locked = $existing->filter(fn (LotRouteStep $s) => $this->hasProgress($lot, $s, $existing))->map(fn (LotRouteStep $s): int => $s->id)->values()->all();
            if (array_diff($locked, $keptIds) !== []) {
                throw new DomainException(__('production.errors.route_step_in_progress'));
            }
            if (array_values(array_intersect($keptIds, $locked)) !== $locked) {
                throw new DomainException(__('production.errors.route_step_order'));
            }
            $before = $this->positionsOf($existing, $locked);

            foreach ($existing as $id => $step) {
                if (! in_array($id, $keptIds, true)) {
                    $step->deleteOrFail();
                }
            }
            // Two passes: park the kept rows on temporary seqs first, so the unique (lot_id, seq) index never collides.
            foreach ($existing->only($keptIds) as $step) {
                $step->update(['seq' => 10000 + $step->id]);
            }
            foreach ($steps as $i => $s) {
                $attrs = ['seq' => ($i + 1) * 10, 'operation_id' => (int) $s['operation_id'], 'is_added' => (bool) ($s['is_added'] ?? false)];
                if (! empty($s['id']) && $existing->has($s['id'])) {
                    $existing[$s['id']]->update($attrs);
                } else {
                    $lot->routeSteps()->create(['is_added' => true] + $attrs); // a step not from the template is always is_added
                }
            }
            $lot->unsetRelation('routeSteps');

            $after = $this->positionsOf($lot->routeSteps()->get()->keyBy('id'), $locked);
            if ($after !== $before) {
                throw new DomainException(__('production.errors.route_step_in_progress'));
            }
            $this->refreshStatuses($lot);
        });
    }

    /** Apply $template to every open lot of the contract that has no progress yet. Returns the number of lots changed. */
    public function applyTemplateToContract(Contract $contract, RouteTemplate $template): int
    {
        $n = 0;
        foreach (Lot::query()->where('contract_id', $contract->id)->open()->get() as $lot) {
            $steps = $lot->routeSteps()->get();
            if ($steps->contains(fn (LotRouteStep $s) => $this->hasProgress($lot, $s, $steps))) {
                continue;
            }
            $this->assign($lot, $template, replace: true);
            $n++;
        }

        return $n;
    }

    /** ISSUED before the first active step, else STEP:<id of the nearest earlier active step>. */
    public function previousPosition(LotRouteStep $step): Position
    {
        return $this->previousOf($step, $this->activeSteps($this->stepsOf($step)));
    }

    /** FINISHED after the last active step, else STEP:<own id>. */
    public function targetPosition(LotRouteStep $step): Position
    {
        return $this->targetOf($step, $this->activeSteps($this->stepsOf($step)));
    }

    /** The first pending/partial step in route order, optionally only one whose operation belongs to $departmentId. */
    public function nextPendingStep(Lot $lot, ?int $departmentId = null): ?LotRouteStep
    {
        return $lot->routeSteps()->with('operation')->whereIn('status', ['pending', 'partial'])->get()
            ->first(fn (LotRouteStep $s) => $departmentId === null || $s->operation->department_id === $departmentId);
    }

    /**
     * pending: nothing processed at this step; done: something processed and nothing left upstream
     * (STOCK, ISSUED, earlier steps); partial otherwise. Skipped steps are left alone.
     */
    public function refreshStatuses(Lot $lot): void
    {
        $balances = $this->ledger->balances($lot);
        $all = $lot->routeSteps()->get();
        $steps = $this->activeSteps($all);
        $upstream = ($balances[Position::STOCK] ?? 0.0) + ($balances[Position::ISSUED] ?? 0.0);
        foreach ($steps as $step) {
            $processed = $this->processedThrough($step, $steps);
            $status = $processed <= 0 ? 'pending' : (round($upstream, 2) <= 0 ? 'done' : 'partial');
            if ($step->status !== $status) {
                $step->update(['status' => $status]);
            }
            $upstream += $balances[Position::step($step->id)->value()] ?? 0.0;
        }
        $lot->unsetRelation('routeSteps');
    }

    /**
     * A step can only be skipped (or un-skipped) while no metres sit at its position and nothing was processed
     * through it, and while doing so leaves the positions of every step with progress where they are.
     */
    public function setSkipped(LotRouteStep $step, bool $skipped): void
    {
        DB::transaction(function () use ($step, $skipped): void {
            $lot = $step->lot;
            $steps = $this->stepsOf($step);
            if ($this->hasProgress($lot, $step, $steps)) {
                throw new DomainException(__('production.errors.route_step_in_progress'));
            }
            $locked = $steps->filter(fn (LotRouteStep $s) => $this->hasProgress($lot, $s, $steps))->map(fn (LotRouteStep $s): int => $s->id)->values()->all();
            $before = $this->positionsOf($steps, $locked);

            $step->update(['status' => $skipped ? 'skipped' : 'pending']);
            $lot->unsetRelation('routeSteps');

            if ($this->positionsOf($lot->routeSteps()->get()->keyBy('id'), $locked) !== $before) {
                throw new DomainException(__('production.errors.route_step_in_progress'));
            }
            $this->refreshStatuses($lot);
        });
    }

    // ---- internals -------------------------------------------------------------------------------------------

    private function defaultTemplate(): ?RouteTemplate
    {
        $id = Setting::get('default_route_template_id');

        return $id === null ? null : RouteTemplate::query()->where('is_active', true)->whereKey($id)->first();
    }

    /** @return Collection<int, LotRouteStep> the lot's whole route, by id (the step itself is part of it) */
    private function stepsOf(LotRouteStep $step): Collection
    {
        return $step->lot->routeSteps()->get()->keyBy('id');
    }

    /**
     * @param  Collection<int, LotRouteStep>  $steps
     * @return Collection<int, LotRouteStep> ordered by seq, skipped excluded, re-indexed
     */
    private function activeSteps(Collection $steps): Collection
    {
        return $steps->filter->isActive()->sortBy('seq')->values();
    }

    /** @param  Collection<int, LotRouteStep>  $active */
    private function previousOf(LotRouteStep $step, Collection $active): Position
    {
        $earlier = $active->filter(fn (LotRouteStep $s) => $s->seq < $step->seq)->last();

        return $earlier === null ? Position::issued() : Position::step($earlier->id);
    }

    /** @param  Collection<int, LotRouteStep>  $active */
    private function targetOf(LotRouteStep $step, Collection $active): Position
    {
        $later = $active->first(fn (LotRouteStep $s) => $s->seq > $step->seq);

        return $later === null ? Position::finished() : Position::step($step->id);
    }

    /**
     * The derived positions of the given steps, used to check that a route edit leaves progressed steps alone.
     *
     * @param  Collection<int, LotRouteStep>  $steps  keyed by id
     * @param  array<int, int>  $ids
     * @return array<int, array{0: string, 1: string}>
     */
    private function positionsOf(Collection $steps, array $ids): array
    {
        $active = $this->activeSteps($steps);
        $out = [];
        foreach ($ids as $id) {
            $step = $steps->get($id);
            if ($step === null) {
                continue;
            }
            $out[$id] = [$this->previousOf($step, $active)->value(), $this->targetOf($step, $active)->value()];
        }

        return $out;
    }

    /** @param  Collection<int, LotRouteStep>  $steps  the lot's whole route */
    private function hasProgress(Lot $lot, LotRouteStep $step, Collection $steps): bool
    {
        return in_array($step->status, ['done', 'partial'], true)
            || $this->ledger->available($lot, Position::step($step->id)) > 0
            || $this->processedThrough($step, $this->activeSteps($steps)) > 0;
    }

    /**
     * Net metres processed into this step's target from its previous position (process movements minus their reversals).
     * A skipped step is never processed through: it has no position of its own.
     *
     * @param  Collection<int, LotRouteStep>  $active
     */
    private function processedThrough(LotRouteStep $step, Collection $active): float
    {
        if (! $step->isActive()) {
            return 0.0;
        }
        $from = $this->previousOf($step, $active)->value();
        $to = $this->targetOf($step, $active)->value();
        $q = LotMovement::query()->where('lot_id', $step->lot_id)->where('kind', 'process');

        return round((float) (clone $q)->where('from_position', $from)->where('to_position', $to)->sum('qty_into_to')
            - (float) (clone $q)->where('from_position', $to)->where('to_position', $from)->sum('qty_out_of_from'), 2);
    }

    private function assertNoProgress(Lot $lot): void
    {
        $steps = $lot->routeSteps()->get();
        if ($steps->contains(fn (LotRouteStep $s) => $this->hasProgress($lot, $s, $steps))) {
            throw new DomainException(__('production.errors.route_in_progress'));
        }
    }
}
