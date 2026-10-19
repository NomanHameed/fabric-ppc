<?php

namespace Database\Seeders;

use App\Modules\Foundation\Models\Stage;
use App\Modules\Production\Models\Lot;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The reporting taxonomy and, with it, the status mapping the resolver works from out of the box.
 *
 * Reference data, not demo data: without it every lot's stage would be null and the Month Wise Fabric Report
 * would have nothing to group by. `updateOrCreate` keyed on the code, so re-seeding an existing database
 * re-states the rows rather than duplicating them.
 *
 * **The list is the user's own (spec §19), verbatim**: Greige · Preparation · Dyeing · Printing · Finishing ·
 * Inspection · Approval · Finished · Dispatch · Exception / Hold · Return · External Processing ·
 * Quality / Approval · Commercial / Adjustment · Closed / Cancelled. Earlier seeds had substituted and
 * invented entries (a Packing stage, a Work in Process stage); those are removed by `prune()` below, and the
 * statuses they claimed are claimed by the stage they belong to.
 *
 * **No stage processes are seeded.** `stage_processes` was restructured by the user (migration
 * `2026_10_13_000100`) and now holds one Oracle production line per row — party, PO, design, colour, lot
 * number, status and the six quantities. There is no process-name taxonomy left to seed: the eighteen rows
 * that were seeded here (Bleaching, Dyeing, Dispatch, …) lost every column that gave them meaning, so the
 * migration deletes them and this seeder does not bring them back. Nothing resolves to a process — the
 * aliases live on the stage, and `lots.stage_process_id` has been gone since `2026_10_09_000100`.
 *
 * **The aliases live on the stage.** The raw statuses a bucket answers for are `stages.status_aliases`.
 * They are the statuses this mill actually writes: the `operations.stage_label` values seeded by
 * FoundationSeeder (`BLEACHING/`, `DYEING/`, `SANFO/CAL`, `ROLLING/`, …), the labels LotStatusLabeller
 * derives (`HOLD/`, `CANCELLED/`, `CLOSED/`, `DISPATCHED/`, `RF-INSP/`, `RF-DISP`, `WIP/`, `DISP dd-mm-yy/`)
 * and the tracker's own codes from the spec (`ON.AAP`, `SB.ON APP`, `RTF`, `OUTSOURCE`, `LCR`,
 * `LCR SB REJECT`, `DEBIT`). The trailing slashes are not repeated here: the resolver normalises them away
 * on both sides. `#BLANK` is the sentinel for "no status at all", which is a lot that has never been issued —
 * greige, and therefore Greige's.
 */
class StageSeeder extends Seeder
{
    /**
     * stage code => [name, description, sort, aliases].
     *
     * @var array<string, array{0: string, 1: string, 2: int, 3: list<string>}>
     */
    private const STAGES = [
        // `#BLANK`: LotStatusLabeller leaves the status empty while every metre is still in stock (rule 6).
        // That lot is greige and nothing else, so Greige claims it rather than the report's unclassified row.
        'GREIGE' => ['Greige', 'Received and still in greige stock, never issued', 10, [Stage::BLANK_ALIAS]],
        'PREP' => ['Preparation', 'Greige preparation before colour', 20, ['BLEACHING', 'PADDING', 'SINGEING', 'MERCERIZING']],
        'DYEING' => ['Dyeing', 'Fabric at the dyeing stage', 30, ['DYEING']],
        // CURING is a printing operation (FoundationSeeder's CURING department), so it is Printing's.
        'PRINTING' => ['Printing', 'Fabric at the printing stage', 40, ['PRINTING', 'CURING']],
        // DRYING, ROLLING and STITCHING are finishing-floor operations with no stage of their own in the
        // user's list, so Finishing answers for them rather than leaving them unmapped.
        'FINISHING' => ['Finishing', 'Fabric being finished', 50, [
            'FINISHING', 'SANFO/CAL', 'SANFO/CAL RP', 'DRYING', 'ROLLING', 'STITCHING',
        ]],
        // No alias: the mill writes no inspection status of its own yet. RF-INSP means "nothing on the floor,
        // metres finished", which is the Finished stage below. Left here, empty, because the user's list has
        // it and a stage with no alias simply never claims a lot.
        'INSPECTION' => ['Inspection', 'Finished fabric in inspection', 60, []],
        'APPROVAL' => ['Approval', 'Waiting for a colour or shade decision', 70, ['ON.AAP', 'SB.ON APP']],
        // `RF-INSP` is LotStatusLabeller rule 5: nothing on the floor and some metres finished.
        'FINISHED' => ['Finished', 'Nothing on the floor; the metres are finished', 80, ['RF-INSP']],
        // `DISP *` is the dated form the tracker writes once a dispatch is recorded (DISP 11-09-26/).
        'DISPATCH' => ['Dispatch', 'Dispatched to the party', 90, ['DISPATCHED', 'RF-DISP', 'DISP *']],
        'EXCEPTION' => ['Exception / Hold', 'Work stopped by a decision, not by a machine', 100, ['HOLD']],
        'RETURN' => ['Return', 'Sent back to the party', 110, ['RTF']],
        'EXTERNAL' => ['External Processing', 'Being processed outside the mill', 120, ['OUTSOURCE']],
        'QUALITY' => ['Quality / Approval', 'Quality review outside the floor', 130, ['LCR', 'LCR SB REJECT']],
        'COMMERCIAL' => ['Commercial / Adjustment', 'Commercial settlement, not production', 140, ['DEBIT']],
        // `*CANCEL*`: anything containing CANCEL, whatever else the status says (spec §7). `CLOSED` belongs
        // here too — it was a process of this stage before the processes stopped carrying aliases.
        'CLOSED' => ['Closed / Cancelled', 'Nothing further will happen to the lot', 150, ['*CANCEL*', 'CLOSED']],
    ];

    /**
     * Stages that earlier seeds invented and the user's list does not have. `prune()` deletes one when
     * nothing points at it and deactivates it when something does.
     *
     * @var list<string>
     */
    private const OBSOLETE = ['PACKING', 'WIP'];

    public function run(): void
    {
        foreach (self::STAGES as $code => [$name, $description, $sort, $aliases]) {
            Stage::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name, 'description' => $description, 'sort_order' => $sort,
                    'status_aliases' => $aliases, 'is_active' => true,
                ],
            );
        }

        $this->prune();
    }

    /**
     * Remove the stages earlier seeds invented.
     *
     * A stage still pointed at by a lot is **deactivated, not deleted** (spec §18): deleting it would null
     * `lots.stage_id` through `nullOnDelete` and silently move those lots into the report's unclassified
     * row. Deactivating keeps the row readable and takes it out of the resolver, and the operator can then
     * decide.
     */
    private function prune(): void
    {
        foreach (self::OBSOLETE as $stageCode) {
            $stage = Stage::query()->where('code', $stageCode)->first();
            if ($stage === null) {
                continue;
            }

            if (Lot::query()->where('stage_id', $stage->getKey())->exists()) {
                $stage->forceFill(['is_active' => false, 'status_aliases' => []])->save();

                continue;
            }
            // `stage_processes.stage_id` restricts the delete, so any row filed under it goes first.
            DB::table('stage_processes')->where('stage_id', $stage->getKey())->delete();
            $stage->delete();
        }
    }
}
