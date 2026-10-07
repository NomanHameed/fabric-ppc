<?php

namespace Database\Seeders;

use App\Modules\Foundation\Models\Stage;
use App\Modules\Foundation\Models\StageProcess;
use Illuminate\Database\Seeder;

/**
 * The reporting taxonomy and, with it, the status mapping the resolver works from out of the box.
 *
 * Reference data, not demo data: without it every lot's stage would be null and the Month Wise Fabric Report
 * would have nothing to group by. `updateOrCreate` keyed on the code, so re-seeding an existing database
 * re-states the rows rather than duplicating them.
 *
 * The aliases are the statuses this mill actually writes: the `operations.stage_label` values seeded by
 * FoundationSeeder (`BLEACHING/`, `DYEING/`, `SANFO/CAL`, `ROLLING/`, …), the labels LotStatusLabeller
 * derives (`HOLD/`, `CANCELLED/`, `CLOSED/`, `DISPATCHED/`, `RF-INSP/`, `RF-DISP`, `WIP/`, `DISP dd-mm-yy/`)
 * and the tracker's own codes from the spec (`ON.AAP`, `SB.ON APP`, `RTF`, `OUTSOURCE`, `LCR`,
 * `LCR SB REJECT`, `DEBIT`). The trailing slashes are not repeated here: the resolver normalises them away
 * on both sides.
 */
class StageSeeder extends Seeder
{
    /**
     * stage code => [name, description, sort, processes], each process
     * [code, name, quantity_type, aliases, description].
     *
     * @var array<string, array{0: string, 1: string, 2: int, 3: list<array{0: string, 1: string, 2: string|null, 3: list<string>, 4: string|null}>}>
     */
    private const STAGES = [
        'PREP' => ['Preparation', 'Greige preparation before colour', 10, [
            ['SINGEING', 'Singeing', 'wip', ['SINGEING'], null],
            ['BLEACHING', 'Bleaching', 'wip', ['BLEACHING'], null],
            ['PADDING', 'Padding', 'wip', ['PADDING'], null],
            ['MERCERIZING', 'Mercerizing', 'wip', ['MERCERIZING'], null],
        ]],
        'DYEING' => ['Dyeing', 'Fabric at the dyeing stage', 20, [
            ['DYEING', 'Dyeing', 'wip', ['DYEING'], null],
        ]],
        'PRINTING' => ['Printing', 'Fabric at the printing stage', 30, [
            ['PRINTING', 'Printing', 'wip', ['PRINTING'], null],
            ['CURING', 'Curing', 'wip', ['CURING'], 'Curing after printing'],
        ]],
        'FINISHING' => ['Finishing', 'Fabric being finished', 40, [
            ['FINISHING', 'Finishing', 'wip', ['FINISHING'], null],
            ['DRYING', 'Drying', 'wip', ['DRYING'], null],
            ['SANFO_CAL', 'Sanforizing / Calendaring', 'wip', ['SANFO/CAL'], null],
            ['SANFO_CAL_RP', 'Sanforizing / Calendaring (reprocess)', 'wip', ['SANFO/CAL RP'], 'Re-run of sanforizing or calendaring'],
        ]],
        'PACKING' => ['Packing', 'Rolling and stitching before inspection', 50, [
            ['ROLLING', 'Rolling', 'wip', ['ROLLING'], null],
            ['STITCHING', 'Stitching', 'wip', ['STITCHING'], null],
        ]],
        'WIP' => ['Work in Process', 'On the floor with no named next operation', 60, [
            ['WIP_UNASSIGNED', 'Unassigned WIP', 'wip', ['WIP'], 'Metres on the floor whose lot has no route step to name the stage'],
        ]],
        'INSPECTION' => ['Inspection', 'Finished fabric awaiting or in inspection', 70, [
            ['FINAL_INSPECTION', 'Final Inspection', 'finished', ['RF-INSP'], 'Nothing on the floor; finished metres ready for inspection'],
        ]],
        'APPROVAL' => ['Approval', 'Waiting for a colour or shade decision', 80, [
            ['APPROVAL_PENDING', 'Approval Pending', null, ['ON.AAP', 'SB.ON APP'], null],
        ]],
        'QUALITY' => ['Quality / Approval', 'Quality review outside the floor', 90, [
            ['LCR_REVIEW', 'LCR Review', null, ['LCR'], null],
            ['LCR_SB_REJECTED', 'LCR / Shade Band Rejected', null, ['LCR SB REJECT'], null],
        ]],
        'EXCEPTION' => ['Exception / Hold', 'Work stopped by a decision, not by a machine', 100, [
            ['HOLD', 'Hold', null, ['HOLD'], null],
        ]],
        'RETURN' => ['Return', 'Sent back to the party', 110, [
            ['RTF', 'Return to Factory', null, ['RTF'], null],
        ]],
        'EXTERNAL' => ['External Processing', 'Being processed outside the mill', 120, [
            ['OUTSOURCE', 'Outsource', null, ['OUTSOURCE'], null],
        ]],
        'COMMERCIAL' => ['Commercial / Adjustment', 'Commercial settlement, not production', 130, [
            ['DEBIT', 'Debit', null, ['DEBIT'], null],
        ]],
        'DISPATCH' => ['Dispatch', 'Dispatched to the party', 140, [
            // `DISP *` is the dated form the tracker writes once a dispatch is recorded (DISP 11-09-26/).
            ['DISPATCH', 'Dispatch', 'dispatched', ['DISPATCHED', 'RF-DISP', 'DISP *'], null],
        ]],
        'CLOSED' => ['Closed / Cancelled', 'Nothing further will happen to the lot', 150, [
            // Anything containing CANCEL, whatever else the status says (spec §7).
            ['CANCELLED', 'Cancelled', null, ['*CANCEL*'], null],
            ['CLOSED', 'Closed', null, ['CLOSED'], 'Closed by PPC, with any remainder recorded as a loss'],
        ]],
    ];

    public function run(): void
    {
        foreach (self::STAGES as $code => [$name, $description, $sort, $processes]) {
            $stage = Stage::updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'description' => $description, 'sort_order' => $sort, 'is_active' => true],
            );

            foreach ($processes as $i => [$processCode, $processName, $quantityType, $aliases, $processDescription]) {
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
                        'status_aliases' => $aliases,
                        'is_active' => true,
                    ],
                );
            }
        }
    }
}
