<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\Stage;
use App\Modules\Production\Models\Lot;
use App\Modules\Production\Status\StatusStageResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Unmapped Production Statuses" (spec §10): every status the mill's lots carry that no **stage** claims,
 * with the number of lots on it, so the operator can add the missing alias instead of discovering the gap as
 * a hole in the month's report.
 *
 * Gated on `masters.manage`, not on a read permission: the point of the screen is to *fix* the gap, which is
 * a change to master data. `map()` is that fix in one action — pick a stage for the status, and the alias is
 * added to that stage and the lots on it re-resolved — so the operator never has to hand-edit a JSON column
 * to classify a status the mill has started writing.
 *
 * Two kinds of lot are deliberately not here:
 *
 *  - a lot with no status at all (`status_oracle` is null — everything still in stock, LotStatusLabeller
 *    rule 6). That is not an unmapped status, it is the absence of one;
 *  - a lot whose stage PPC pinned by hand. Its stage was chosen, not resolved, so a status that only such
 *    lots carry is not a mapping gap.
 */
class UnmappedStatusController extends Controller
{
    /** Statuses listed at most; a mill with more than this has a seeding problem, not a mapping gap. */
    public const ROW_CAP = 200;

    public function __construct(private readonly StatusStageResolver $resolver) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('masters.manage'), 403);

        $rows = DB::table('lots')
            ->whereNull('stage_overridden_at')
            ->whereNotNull('status_oracle')
            ->groupBy('status_oracle')
            ->selectRaw('status_oracle as label, count(*) as lots, min(id) as lot_id')
            ->orderByDesc('lots')->orderBy('status_oracle')
            ->get();

        $unmapped = $rows
            ->reject(fn (object $row): bool => $this->resolver->isMapped((string) $row->label))
            ->map(fn (object $row): array => [
                // The grid keys rows by `id`; the lowest lot id on the status is both unique per row and
                // the lot the row opens.
                'id' => (int) $row->lot_id,
                'label' => (string) $row->label,
                // What the resolver actually compares, so "DYEING /" and "dyeing" are visibly one status.
                'normalised' => StatusStageResolver::normalise((string) $row->label),
                'lots' => (int) $row->lots,
                'lot_id' => (int) $row->lot_id,
            ])
            ->values();

        return Inertia::render('production/UnmappedStatuses', [
            'statuses' => $unmapped->take(self::ROW_CAP),
            'statusesTotal' => $unmapped->count(),
            'lotsAffected' => $unmapped->sum('lots'),
            // Lots with no stage for any reason: the figure the report's "unclassified" row would show.
            'lotsWithoutStage' => DB::table('lots')->whereNull('stage_id')->count(),
        ]);
    }

    /**
     * Map one raw status to a stage: add it to that stage's aliases and re-resolve the lots that carry it
     * (spec §10, the report's own workflow).
     *
     * The alias is stored **normalised**, the same form the resolver compares — so `DYEING /`, `dyeing` and
     * `DYEING/` are one alias, not three near-duplicates that drift apart.
     *
     * A status another stage already claims is refused, naming that stage: appending it here would silently
     * re-point every historical lot on that status, and a report figure must never move because of a mapping
     * nobody was told about. Re-mapping to the stage that already owns it is a no-op.
     *
     * Lots whose stage was pinned by hand are left alone and counted separately, exactly as
     * `production:sync-stages` leaves them.
     */
    public function map(Request $request, Stage $stage): RedirectResponse
    {
        $user = $request->user();
        // Before any validation, so a user who may not map gets 403 whatever the payload is.
        abort_unless($user->can('masters.manage'), 403);

        // 40 characters: the width of `lots.status_oracle`, so no alias can be longer than a status.
        $data = $request->validate(['status' => ['required', 'string', 'max:40']]);
        $alias = self::assertAlias($data['status']);

        if ($stage->is_active !== true) {
            throw ValidationException::withMessages(['stage_id' => __('production.lots.stage_inactive')]);
        }

        $this->assertNotClaimedElsewhere($alias, $stage);

        $aliases = $stage->aliasList();
        $already = array_filter($aliases, fn (string $one): bool => StatusStageResolver::normalise($one) === $alias);
        if ($already === []) {
            // An audited save: adding an alias re-classifies historical production, so it belongs in the
            // audit trail with the user who did it.
            $stage->status_aliases = [...$aliases, $alias];
            $stage->save();
        }
        $this->resolver->flush();

        [$resolved, $skipped] = $this->reresolve($alias, $stage);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('production.unmapped.mapped', [
            'status' => $alias,
            'process' => $stage->name,
            'lots' => $resolved,
            'skipped' => $skipped,
        ])]);

        return back();
    }

    /**
     * The normalised alias, or a field error. An alias must say something: blank, whitespace or punctuation
     * only would normalise to nothing (or to a string with no letter or digit in it) and then either match
     * nothing or, worse, be offered as a mapping that cannot ever apply.
     */
    private static function assertAlias(string $status): string
    {
        $alias = StatusStageResolver::normalise($status);
        if ($alias === null || preg_match('/[\p{L}\p{N}]/u', $alias) !== 1) {
            throw ValidationException::withMessages(['status' => __('production.unmapped.alias_blank')]);
        }

        return $alias;
    }

    /** Refuse an alias some other stage already answers for (exactly or through a wildcard). */
    private function assertNotClaimedElsewhere(string $alias, Stage $stage): void
    {
        $owner = $this->resolver->stageId($alias);
        if ($owner === null || $owner === (int) $stage->getKey()) {
            return;
        }

        $other = Stage::query()->find($owner);
        throw ValidationException::withMessages(['status' => __('production.unmapped.alias_taken', [
            'process' => $other === null ? (string) $owner : $other->name,
        ])]);
    }

    /**
     * Point every lot carrying this status at the stage, and say how many moved and how many were left
     * because their stage is pinned.
     *
     * The match is on the normalised form, which SQL cannot express, so the distinct statuses in use (a
     * handful of rows) are filtered in PHP and the update itself is one statement over the matching ones.
     * A query-builder update, like LedgerService's: this is a derived column and a re-classification must
     * not stamp `updated_at` on lots nobody edited.
     *
     * @return array{0: int, 1: int} [re-resolved, skipped because overridden]
     */
    private function reresolve(string $alias, Stage $stage): array
    {
        $labels = DB::table('lots')->whereNotNull('status_oracle')->distinct()->pluck('status_oracle')
            ->filter(fn ($label): bool => StatusStageResolver::normalise((string) $label) === $alias)
            ->map(fn ($label): string => (string) $label)
            ->values()->all();

        if ($labels === []) {
            return [0, 0];
        }

        $skipped = Lot::query()->whereIn('status_oracle', $labels)->whereNotNull('stage_overridden_at')->count();
        $resolved = Lot::query()->whereIn('status_oracle', $labels)->whereNull('stage_overridden_at')->count();
        if ($resolved > 0) {
            DB::table('lots')->whereIn('status_oracle', $labels)->whereNull('stage_overridden_at')
                ->update(['stage_id' => (int) $stage->getKey()]);
        }

        return [$resolved, $skipped];
    }
}
