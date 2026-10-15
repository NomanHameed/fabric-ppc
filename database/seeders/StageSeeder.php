<?php

namespace Database\Seeders;

use App\Modules\Foundation\Models\Stage;
use App\Modules\Foundation\Models\StageProcess;
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
 * **The list is the user's own (spec §19), verbatim**: Greige · Preparation (Bleaching, Padding) · Dyeing ·
 * Printing · Finishing (Finishing, Sanforizing / Calendaring, Sanforizing / Calendaring - Reprocess) ·
 * Inspection (Final Inspection) · Approval (Approval Pending) · Finished · Dispatch · Exception / Hold ·
 * Return · External Processing · Quality / Approval (LCR Review, LCR / Shade Band Rejected) ·
 * Commercial / Adjustment · Closed / Cancelled. Earlier seeds had substituted and invented entries (a
 * Packing stage, a Work in Process stage, Singeing, Mercerizing, Curing, Drying and a Closed process); those
 * are removed by `prune()` below, and the statuses they claimed are claimed by the stage they belong to.
 *
 * **The aliases live on the stage.** Resolution is stage-only (`lots.stage_process_id` is gone), so the raw
 * statuses a bucket answers for are `stages.status_aliases`. The processes are still seeded — they are the
 * mill's own naming of the positions inside a stage, and the Stage Processes master still edits them — but
 * nothing resolves to them and their own `status_aliases` column is left empty.
 *
 * The aliases are the statuses this mill actually writes: the `operations.stage_label` values seeded by
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
     * stage code => [name, description, sort, aliases, processes], each process [code, name, quantity_type,
     * description].
     *
     * @var array<string, array{0: string, 1: string, 2: int, 3: list<string>, 4: list<array{0: string, 1: string, 2: string|null, 3: string|null}>}>
     */
    private const STAGES = [
        // `#BLANK`: LotStatusLabeller leaves the status empty while every metre is still in stock (rule 6).
        // That lot is greige and nothing else, so Greige claims it rather than the report's unclassified row.
        'GREIGE' => ['Greige', 'Received and still in greige stock, never issued', 10, [Stage::BLANK_ALIAS], []],
        'PREP' => ['Preparation', 'Greige preparation before colour', 20, ['BLEACHING', 'PADDING', 'SINGEING', 'MERCERIZING'], [
            ['BLEACHING', 'Bleaching', 'wip', null],
            ['PADDING', 'Padding', 'wip', null],
        ]],
        'DYEING' => ['Dyeing', 'Fabric at the dyeing stage', 30, ['DYEING'], [
            ['DYEING', 'Dyeing', 'wip', null],
        ]],
        // CURING is a printing operation (FoundationSeeder's CURING department), so it is Printing's.
        'PRINTING' => ['Printing', 'Fabric at the printing stage', 40, ['PRINTING', 'CURING'], [
            ['PRINTING', 'Printing', 'wip', null],
        ]],
        // DRYING, ROLLING and STITCHING are finishing-floor operations with no stage of their own in the
        // user's list, so Finishing answers for them rather than leaving them unmapped.
        'FINISHING' => ['Finishing', 'Fabric being finished', 50, [
            'FINISHING', 'SANFO/CAL', 'SANFO/CAL RP', 'DRYING', 'ROLLING', 'STITCHING',
        ], [
            ['FINISHING', 'Finishing', 'wip', null],
            ['SANFO_CAL', 'Sanforizing / Calendaring', 'wip', null],
            ['SANFO_CAL_RP', 'Sanforizing / Calendaring - Reprocess', 'wip', 'Re-run of sanforizing or calendaring'],
        ]],
        // No alias: the mill writes no inspection status of its own yet. RF-INSP means "nothing on the floor,
        // metres finished", which is the Finished stage below. Left here, empty, because the user's list has
        // it and a stage with no alias simply never claims a lot.
        'INSPECTION' => ['Inspection', 'Finished fabric in inspection', 60, [], [
            ['FINAL_INSPECTION', 'Final Inspection', 'finished', 'Inspection of finished metres'],
        ]],
        'APPROVAL' => ['Approval', 'Waiting for a colour or shade decision', 70, ['ON.AAP', 'SB.ON APP'], [
            ['APPROVAL_PENDING', 'Approval Pending', null, null],
        ]],
        // `RF-INSP` is LotStatusLabeller rule 5: nothing on the floor and some metres finished.
        'FINISHED' => ['Finished', 'Nothing on the floor; the metres are finished', 80, ['RF-INSP'], [
            ['FINISHED', 'Finished', 'finished', null],
        ]],
        'DISPATCH' => ['Dispatch', 'Dispatched to the party', 90, ['DISPATCHED', 'RF-DISP', 'DISP *'], [
            // `DISP *` is the dated form the tracker writes once a dispatch is recorded (DISP 11-09-26/).
            ['DISPATCH', 'Dispatch', 'dispatched', null],
        ]],
        'EXCEPTION' => ['Exception / Hold', 'Work stopped by a decision, not by a machine', 100, ['HOLD'], [
            ['HOLD', 'Hold', null, null],
        ]],
        'RETURN' => ['Return', 'Sent back to the party', 110, ['RTF'], [
            ['RTF', 'Return to Factory', null, null],
        ]],
        'EXTERNAL' => ['External Processing', 'Being processed outside the mill', 120, ['OUTSOURCE'], [
            ['OUTSOURCE', 'Outsource', null, null],
        ]],
        'QUALITY' => ['Quality / Approval', 'Quality review outside the floor', 130, ['LCR', 'LCR SB REJECT'], [
            ['LCR_REVIEW', 'LCR Review', null, null],
            ['LCR_SB_REJECTED', 'LCR / Shade Band Rejected', null, null],
        ]],
        'COMMERCIAL' => ['Commercial / Adjustment', 'Commercial settlement, not production', 140, ['DEBIT'], [
            ['DEBIT', 'Debit', null, null],
        ]],
        // `*CANCEL*`: anything containing CANCEL, whatever else the status says (spec §7). `CLOSED` belongs
        // here too — it was a process of this stage before the processes stopped carrying aliases.
        'CLOSED' => ['Closed / Cancelled', 'Nothing further will happen to the lot', 150, ['*CANCEL*', 'CLOSED'], [
            ['CANCELLED', 'Cancelled', null, null],
        ]],
    ];

    /**
     * Stages, and processes within a kept stage, that earlier seeds invented and the user's list does not
     * have. `prune()` deletes them when nothing points at them and deactivates them when something does.
     *
     * @var array<string, list<string>> stage code => process codes; an empty list means the whole stage
     */
    private const OBSOLETE = [
        'PACKING' => [],
        'WIP' => [],
        'PREP' => ['SINGEING', 'MERCERIZING'],
        'PRINTING' => ['CURING'],
        'FINISHING' => ['DRYING'],
        'CLOSED' => ['CLOSED'],
    ];

    public function run(): void
    {
        foreach (self::STAGES as $code => [$name, $description, $sort, $aliases, $processes]) {
            $stage = Stage::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name, 'description' => $description, 'sort_order' => $sort,
                    'status_aliases' => $aliases, 'is_active' => true,
                ],
            );

            foreach ($processes as $i => [$processCode, $processName, $quantityType, $processDescription]) {
                StageProcess::updateOrCreate(
                    ['stage_id' => $stage->getKey(), 'code' => $processCode],
                    [
                        'name' => $processName,
                        'description' => $processDescription,
                        'sort_order' => ($i + 1) * 10,
                        'quantity_type' => $quantityType,
                        // One flag per quantity kind, so a report can ask "is this WIP?" without knowing the
                        // vocabulary; they are derived from the quantity type and cannot disagree with it.
                        'is_wip' => $quantityType === 'wip',
                        'is_finished' => $quantityType === 'finished',
                        'is_dispatch' => $quantityType === 'dispatched',
                        // Aliases live on the stage now; a process carries none.
                        'status_aliases' => [],
                        'is_active' => true,
                    ],
                );
            }
        }

        $this->prune();
    }

    /**
     * Remove the stages and processes earlier seeds invented.
     *
     * A stage still pointed at by a lot is **deactivated, not deleted** (spec §18): deleting it would null
     * `lots.stage_id` through `nullOnDelete` and silently move those lots into the report's unclassified
     * row. Deactivating keeps the row readable and takes it out of the resolver, and the operator can then
     * decide. A process is deleted outright — nothing references one any more.
     */
    private function prune(): void
    {
        foreach (self::OBSOLETE as $stageCode => $processCodes) {
            $stage = Stage::query()->where('code', $stageCode)->first();
            if ($stage === null) {
                continue;
            }

            if ($processCodes !== []) {
                StageProcess::query()->where('stage_id', $stage->getKey())->whereIn('code', $processCodes)->delete();

                continue;
            }

            if (Lot::query()->where('stage_id', $stage->getKey())->exists()) {
                $stage->forceFill(['is_active' => false, 'status_aliases' => []])->save();

                continue;
            }
            // `stage_processes.stage_id` restricts the delete, so the processes go first.
            DB::table('stage_processes')->where('stage_id', $stage->getKey())->delete();
            $stage->delete();
        }
    }
}
